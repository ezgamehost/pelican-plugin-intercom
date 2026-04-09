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

    public function test_returns_null_when_identity_secret_is_blank(): void
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

        config()->set('intercom.app_id', 'test-app-id');
        config()->set('intercom.identity_secret', '');

        $this->assertNull(IntercomBootPayload::forCurrentUser());
    }

    public function test_returns_full_payload_when_authenticated_and_configured(): void
    {
        $createdAt = now();
        $user = new \App\Models\User();
        $user->forceFill([
            'id' => 42,
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'email' => 'alice@example.com',
            'username' => 'alice',
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
            'created_at' => $createdAt,
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', 'my-app-id');
        config()->set('intercom.identity_secret', 'my-secret');

        $payload = IntercomBootPayload::forCurrentUser();

        $this->assertIsArray($payload);
        $this->assertSame('my-app-id', $payload['app_id']);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $payload['user_id']);
        $this->assertSame('alice@example.com', $payload['email']);
        $this->assertSame('alice', $payload['name']);
        $this->assertSame($createdAt->timestamp, $payload['created_at']);
        $this->assertSame('de', $payload['language_override']);
        $this->assertSame('Europe/Berlin', $payload['timezone']);
    }
}
