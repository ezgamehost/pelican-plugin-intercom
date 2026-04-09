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

        // The Intercom widget loader must be present.
        $this->assertStringContainsString("widget.intercom.io/widget/", $output);
        $this->assertStringContainsString('reattach_activator', $output);

        // Hash must be non-empty hex.
        $this->assertMatchesRegularExpression('/"user_hash":"[0-9a-f]{64}"/', $output);
    }
}
