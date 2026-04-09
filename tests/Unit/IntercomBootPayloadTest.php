<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use EzGameHostLlc\Intercom\Services\IntercomBootPayload;

class IntercomBootPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PluginService::loadPlugins() early-returns in tests, so we must
        // register the plugin's PSR-4 mapping manually. addPsr4 is
        // idempotent — composer's ClassLoader de-dupes on re-registration.
        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));

        config()->set('intercom', require base_path('plugins/intercom/config/intercom.php'));
    }

    public function test_returns_null_when_unauthenticated(): void
    {
        auth()->logout();
        config()->set('intercom.app_id', 'test-app-id');
        config()->set('intercom.identity_secret', 'test-secret');

        $this->assertNull(IntercomBootPayload::forCurrentUser());
    }

    public function test_returns_null_when_app_id_is_blank(): void
    {
        $user = new \App\Models\User();
        $user->forceFill([
            'id' => 1,
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'email' => 'user@example.com',
            'username' => 'testuser',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', '');
        config()->set('intercom.identity_secret', 'test-secret');

        $this->assertNull(IntercomBootPayload::forCurrentUser());
    }
}
