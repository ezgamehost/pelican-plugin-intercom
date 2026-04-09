<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Models\User;
use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use EzGameHostLlc\Intercom\Services\IntercomBootPayload;

class IntercomBootPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PluginService::loadPlugins() early-returns in tests, so we must
        // register the plugin's PSR-4 mapping manually. Re-registering the
        // same prefix on subsequent test methods is a no-op in composer's
        // ClassLoader.
        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));

        config()->set('intercom', require base_path('plugins/intercom/config/intercom.php'));
    }

    /**
     * Build an unsaved User model with the fields the payload service reads.
     * Overrides merge shallowly so tests can set only what they care about.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeUser(array $overrides = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'id' => 1,
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'email' => 'user@example.com',
            'username' => 'testuser',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ], $overrides));

        return $user;
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
        $this->actingAs($this->makeUser());

        config()->set('intercom.app_id', '');
        config()->set('intercom.identity_secret', 'test-secret');

        $this->assertNull(IntercomBootPayload::forCurrentUser());
    }

    public function test_returns_null_when_identity_secret_is_blank(): void
    {
        $this->actingAs($this->makeUser());

        config()->set('intercom.app_id', 'test-app-id');
        config()->set('intercom.identity_secret', '');

        $this->assertNull(IntercomBootPayload::forCurrentUser());
    }

    public function test_returns_full_payload_when_authenticated_and_configured(): void
    {
        $createdAt = now();
        $this->actingAs($this->makeUser([
            'id' => 42,
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'email' => 'alice@example.com',
            'username' => 'alice',
            'language' => 'de',
            'timezone' => 'Europe/Berlin',
            'created_at' => $createdAt,
        ]));

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
        // Sanity-check the hash is present and shaped like hex SHA-256 output;
        // exact value is verified by test_user_hash_is_hmac_sha256_of_uuid.
        $this->assertArrayHasKey('user_hash', $payload);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['user_hash']);
    }

    public function test_user_hash_is_hmac_sha256_of_uuid(): void
    {
        $this->actingAs($this->makeUser([
            'uuid' => 'known-uuid-fixture',
        ]));

        config()->set('intercom.app_id', 'app');
        config()->set('intercom.identity_secret', 'known-secret');

        $payload = IntercomBootPayload::forCurrentUser();
        $expected = hash_hmac('sha256', 'known-uuid-fixture', 'known-secret');

        $this->assertSame($expected, $payload['user_hash']);
    }

    public function test_user_id_is_uuid_not_integer_id(): void
    {
        $this->actingAs($this->makeUser([
            'id' => 12345,
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]));

        config()->set('intercom.app_id', 'app');
        config()->set('intercom.identity_secret', 'secret');

        $payload = IntercomBootPayload::forCurrentUser();

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $payload['user_id']);
        $this->assertNotSame(12345, $payload['user_id']);
    }

    public function test_payload_keys_match_whitelist_exactly(): void
    {
        $this->actingAs($this->makeUser([
            'uuid' => '33333333-3333-3333-3333-333333333333',
        ]));

        config()->set('intercom.app_id', 'app');
        config()->set('intercom.identity_secret', 'secret');

        $payload = IntercomBootPayload::forCurrentUser();

        $expectedKeys = [
            'app_id',
            'user_id',
            'user_hash',
            'email',
            'name',
            'created_at',
            'language_override',
            'timezone',
        ];

        sort($expectedKeys);
        $actualKeys = array_keys($payload);
        sort($actualKeys);

        $this->assertSame(
            $expectedKeys,
            $actualKeys,
            'IntercomBootPayload leaks or drops a field — every key change needs a privacy review. See spec §8 (PII whitelist).'
        );
    }
}
