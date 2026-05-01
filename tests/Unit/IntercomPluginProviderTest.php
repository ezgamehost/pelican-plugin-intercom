<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Models\User;
use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use EzGameHostLlc\Intercom\Providers\IntercomPluginProvider;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\View;

class IntercomPluginProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // parent::setUp() must run first — base_path() requires Laravel booted.
        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));

        config()->set('intercom', require base_path('plugins/intercom/config/intercom.php'));
        View::addNamespace('intercom', base_path('plugins/intercom/resources/views'));
    }

    public function test_provider_registers_without_error(): void
    {
        // If this throws, the test fails. We deliberately don't assert anything
        // about the render hook because that's framework plumbing we trust.
        // The purpose of this test is to catch typos, missing imports, or
        // facade resolution errors that would break plugin load-time.
        $this->app->register(IntercomPluginProvider::class);

        $this->assertTrue(true);
    }

    public function test_provider_merges_intercom_config(): void
    {
        // Wipe any pre-existing intercom config so we see the merge effect.
        // Use an empty array (not null) because mergeConfigFrom calls array_merge()
        // internally and array_merge() requires an array, not null.
        config()->set('intercom', []);

        $this->app->register(IntercomPluginProvider::class);

        $this->assertNotNull(config('intercom.api_base'));
        $this->assertSame('https://widget.intercom.io', config('intercom.api_base'));
    }

    public function test_provider_renders_boot_view_on_app_panel(): void
    {
        $this->actingAsConfiguredUser();

        Filament::shouldReceive('getCurrentPanel')
            ->once()
            ->andReturn($this->fakePanel('app'));

        $provider = new IntercomPluginProvider($this->app);

        $output = $provider->renderBootScriptForCurrentPanel();

        $this->assertStringContainsString('window.intercomSettings', $output);
        $this->assertStringContainsString('"app_id":"workspace-xyz"', $output);
    }

    public function test_provider_renders_boot_view_on_server_panel(): void
    {
        $this->actingAsConfiguredUser();

        Filament::shouldReceive('getCurrentPanel')
            ->once()
            ->andReturn($this->fakePanel('server'));

        $provider = new IntercomPluginProvider($this->app);

        $output = $provider->renderBootScriptForCurrentPanel();

        $this->assertStringContainsString('window.intercomSettings', $output);
        $this->assertStringContainsString('"app_id":"workspace-xyz"', $output);
    }

    public function test_provider_does_not_render_boot_view_on_admin_panel(): void
    {
        $this->actingAsConfiguredUser();

        Filament::shouldReceive('getCurrentPanel')
            ->once()
            ->andReturn($this->fakePanel('admin'));

        $provider = new IntercomPluginProvider($this->app);

        $this->assertSame('', $provider->renderBootScriptForCurrentPanel());
    }

    private function fakePanel(string $id): object
    {
        return new class($id) {
            public function __construct(private readonly string $id) {}

            public function getId(): string
            {
                return $this->id;
            }
        };
    }

    private function actingAsConfiguredUser(): void
    {
        $user = new User();
        $user->forceFill([
            'id' => 1,
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'email' => 'support-test@example.com',
            'username' => 'support-test',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);

        $this->actingAs($user);

        config()->set('intercom.app_id', 'workspace-xyz');
        config()->set('intercom.identity_secret', 'secret');
    }
}
