<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Models\User;
use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\View;

class BootViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));

        config()->set('intercom', require base_path('plugins/intercom/config/intercom.php'));

        // Register the plugin's view namespace — PluginService doesn't run this in tests.
        View::addNamespace('intercom', base_path('plugins/intercom/resources/views'));
    }

    public function test_renders_empty_string_when_payload_is_null(): void
    {
        // Unauthenticated + blank config → forCurrentUser() returns null
        auth()->logout();
        config()->set('intercom.app_id', '');
        config()->set('intercom.identity_secret', '');

        $output = trim((string) view('intercom::boot')->render());

        $this->assertSame('', $output);
    }

    public function test_renders_intercom_script_with_inlined_payload(): void
    {
        $user = new User();
        $user->forceFill([
            'id' => 1,
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'email' => 'bob@example.com',
            'username' => 'bob',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', 'workspace-xyz');
        config()->set('intercom.identity_secret', 'secret');

        $output = view('intercom::boot')->render();

        // The inlined settings object must be present and correctly escaped.
        $this->assertStringContainsString('window.intercomSettings', $output);
        $this->assertStringContainsString('"app_id":"workspace-xyz"', $output);
        $this->assertStringContainsString('"user_id":"44444444-4444-4444-4444-444444444444"', $output);
        $this->assertStringContainsString('"email":"bob@example.com"', $output);

        // The widget loader URL must resolve to the default US origin
        // + /widget/ + the configured app_id.
        $this->assertStringContainsString(
            '"https://widget.intercom.io/widget/workspace-xyz"',
            $output
        );
        $this->assertStringContainsString('reattach_activator', $output);

        // Hash must be non-empty hex.
        $this->assertMatchesRegularExpression('/"user_hash":"[0-9a-f]{64}"/', $output);
    }

    public function test_custom_api_base_propagates_to_widget_loader_url(): void
    {
        // EU/AU data-residency override. A hosted Intercom workspace in the
        // EU region uses a different origin for the widget CDN; setting
        // INTERCOM_API_BASE must actually change the URL the loader fetches.
        // If the view hardcoded the default origin instead of reading
        // config('intercom.api_base'), this test catches it.
        $user = new User();
        $user->forceFill([
            'id' => 1,
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'email' => 'eve@example.eu',
            'username' => 'eve',
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', 'eu-workspace');
        config()->set('intercom.identity_secret', 'eu-secret');
        // Include a trailing slash to also verify the rtrim normalization.
        config()->set('intercom.api_base', 'https://eu.intercom-widget.example/');

        $output = view('intercom::boot')->render();

        $this->assertStringContainsString(
            '"https://eu.intercom-widget.example/widget/eu-workspace"',
            $output
        );
        // And the default US origin must NOT appear anywhere.
        $this->assertStringNotContainsString(
            'widget.intercom.io',
            $output
        );
    }

    public function test_user_data_containing_script_tag_is_hex_escaped(): void
    {
        // Regression guard: if JSON_HEX_TAG is ever removed or the payload
        // is interpolated without json_encode, user-controlled strings like
        // this username could break out of the <script> context. The flag
        // must convert `<` → `\u003c` and `>` → `\u003e`.
        $user = new User();
        $user->forceFill([
            'id' => 1,
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'email' => 'mallory@example.com',
            'username' => '</script><script>alert(1)</script>',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', 'workspace-xyz');
        config()->set('intercom.identity_secret', 'secret');

        $output = view('intercom::boot')->render();

        // The literal closing tag must NOT appear anywhere outside a
        // legitimate Blade-emitted </script> (the two script closers in
        // the template). Count them: there should be exactly 2, one per
        // emitted <script> block. Any more means user data leaked out.
        $this->assertSame(
            2,
            substr_count($output, '</script>'),
            'User data containing </script> was not hex-escaped — XSS risk.'
        );

        // And the hex-escaped form should appear in the payload JSON.
        // PHP's JSON_HEX_TAG emits uppercase hex: `<` → `\u003C`, `>` → `\u003E`;
        // JSON_UNESCAPED_SLASHES keeps `/` as `/`.
        $this->assertStringContainsString('\u003C/script\u003E', $output);
    }
}
