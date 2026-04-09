# Pelican Intercom Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `intercom` plugin for Pelican Panel v1 that embeds the Intercom Messenger on the client-area (`server`) and server-list (`app`) panels with server-side HMAC Identity Verification.

**Architecture:** A Laravel ServiceProvider registers a Filament render hook (scoped to the two end-user panels) that renders a Blade view. The Blade view calls a pure `IntercomBootPayload::forCurrentUser()` service which returns either `null` (when unauthenticated or misconfigured) or a whitelisted identity + locale payload. The plugin class implements `HasPluginSettings` so the host panel's Admin → Plugins page auto-renders a settings slide-over that persists three `.env` keys via `EnvironmentWriterTrait`. Zero runtime composer dependencies.

**Tech Stack:** PHP 8.2+, Laravel 12, Filament v4, Pest 3 (PHPUnit classes extending `App\Tests\TestCase`), Blade, `hash_hmac('sha256', ...)` from PHP's standard library.

---

## Background & conventions for the implementer

**Two directories, one plugin.** The standalone git repo lives at `/Users/matthewzhao/code/pelican-plugin-intercom`. The host Pelican Panel lives at `/Users/matthewzhao/code/panel`. A symlink at `/Users/matthewzhao/code/panel/plugins/intercom` pointing to the plugin repo allows the panel to discover the plugin (`app/Services/Helpers/PluginService.php:123` scans `base_path('plugins/')`).

**All file paths in this plan are relative to `/Users/matthewzhao/code/pelican-plugin-intercom/`** (the plugin repo root), unless prefixed with `panel/` which means inside the host panel.

**Commands have explicit `cd`.** Commits happen in the plugin repo. Test runs happen from the panel root (because tests depend on the panel's autoload-dev and Laravel bootstrap).

**Testing convention.** The panel uses classic PHPUnit-style test classes (not Pest closures), extending `App\Tests\TestCase` (panel's `tests/TestCase.php`). See existing example at `panel/tests/Unit/Helpers/ConvertToUtf8Test.php`. Plugin tests follow the same convention.

**Plugin autoload in tests.** `PluginService::loadPlugins()` early-returns in unit tests (`PluginService.php:35-37`), so the PSR-4 mapping `EzGameHostLlc\Intercom\` → `plugins/intercom/src/` is NOT registered during test runs. Each plugin test class manually registers the mapping in its `setUp()`. The registration is idempotent — composer's ClassLoader caches and de-dupes PSR-4 prefixes.

**Spec reference.** This plan implements the spec at `docs/superpowers/specs/2026-04-08-intercom-plugin-design.md`. Read that first for context.

---

## File structure (what gets created)

| Path (relative to plugin repo root) | Purpose |
|---|---|
| `plugin.json` | Plugin metadata consumed by `PluginService` |
| `.gitignore` | Ignore `.phpunit.result.cache`, `.idea/`, `.vscode/` |
| `README.md` | Install / configure / support contact (`infra@ezgamehost.com`) |
| `config/intercom.php` | Maps env vars to `config('intercom.*')` |
| `src/IntercomPlugin.php` | Filament `Plugin` + `HasPluginSettings` implementation |
| `src/Providers/IntercomPluginProvider.php` | Laravel `ServiceProvider`; registers the render hook |
| `src/Services/IntercomBootPayload.php` | Pure function building identity + attributes payload |
| `resources/views/boot.blade.php` | Renders the Intercom JS snippet with the payload inlined |
| `tests/Unit/IntercomBootPayloadTest.php` | Payload unit tests + field whitelist regression guard |
| `tests/Unit/IntercomPluginSettingsTest.php` | `saveSettings` → `.env` integration test |
| `tests/Unit/BootViewTest.php` | Blade view rendering test |
| `tests/Unit/IntercomPluginProviderTest.php` | Smoke test: provider boots without throwing |

---

## Task 0: Bootstrap scaffolding and local discovery

Creates the file skeleton, symlinks the plugin into the host panel, and verifies Pelican can see the plugin (before we add any logic).

**Files:**
- Create: `.gitignore`
- Create: `plugin.json`
- Create: `config/intercom.php`
- Create: `src/` (empty dir, created implicitly by later file creates)
- Create: `src/Providers/` (same)
- Create: `src/Services/` (same)
- Create: `resources/views/` (same)
- Create: `tests/Unit/` (same)
- Modify (symlink): `panel/plugins/intercom` → plugin repo root

- [ ] **Step 1: Resolve `panel_version` from the running panel**

Run:
```bash
cd /Users/matthewzhao/code/panel && php artisan tinker --execute="echo config('app.version');"
```

Expected: prints something like `1.0.0-beta25` or `canary`. Record the value. If it is `canary`, the plugin.json field `panel_version` will be `null` (no constraint). Otherwise, prefix with `^` (e.g., `^1.0.0-beta25`) to match the pattern `MakePluginCommand.php:87-91` uses for a non-strict constraint.

Store this value — you will inline it into `plugin.json` in Step 3.

- [ ] **Step 2: Create `.gitignore`**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/.gitignore`:

```
.phpunit.result.cache
.phpunit.cache/
.idea/
.vscode/
.DS_Store
```

- [ ] **Step 3: Create `plugin.json`**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/plugin.json`. Replace `<PANEL_VERSION_CONSTRAINT>` with the value from Step 1 (either a quoted version string like `"^1.0.0-beta25"` or `null` if canary):

```json
{
  "id": "intercom",
  "name": "Intercom",
  "author": "EZ Game Host LLC",
  "version": "1.0.0",
  "description": "Embeds the Intercom Messenger on end-user Pelican panels with verified identity for authenticated support conversations.",
  "category": "plugin",
  "url": "https://github.com/ezgamehost/pelican-plugin-intercom",
  "update_url": "https://raw.githubusercontent.com/ezgamehost/pelican-plugin-intercom/main/updates.json",
  "namespace": "EzGameHostLlc\\Intercom",
  "class": "IntercomPlugin",
  "panels": ["server", "app"],
  "panel_version": <PANEL_VERSION_CONSTRAINT>,
  "composer_packages": null,
  "meta": {
    "status": "not-installed",
    "status_message": null
  }
}
```

Note the two backslashes in `"namespace": "EzGameHostLlc\\Intercom"` — this is a JSON string containing a single backslash, which PHP then uses as the namespace separator when it loads the plugin.

- [ ] **Step 4: Create `config/intercom.php`**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/config/intercom.php`:

```php
<?php

return [
    'app_id' => env('INTERCOM_APP_ID'),
    'identity_secret' => env('INTERCOM_IDENTITY_SECRET'),
    'api_base' => env('INTERCOM_API_BASE', 'https://widget.intercom.io'),
];
```

- [ ] **Step 5: Symlink the plugin into the host panel**

Run:
```bash
ln -s /Users/matthewzhao/code/pelican-plugin-intercom /Users/matthewzhao/code/panel/plugins/intercom
```

Verify the symlink:
```bash
ls -la /Users/matthewzhao/code/panel/plugins/intercom/plugin.json
```

Expected: shows the symlinked `plugin.json` file (no "No such file or directory" error). Note that `panel/plugins/.gitignore` ignores everything except itself, so adding the symlink there won't dirty the panel's git state.

- [ ] **Step 6: Verify Pelican discovers the plugin**

Run:
```bash
cd /Users/matthewzhao/code/panel && php artisan p:plugin:list
```

Expected: output includes a row for `intercom` with author `EZ Game Host LLC`, status `not-installed`. If you see a JSON parse error or `plugin.json is invalid`, re-check Step 3.

- [ ] **Step 7: Commit the scaffolding**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add .gitignore plugin.json config/intercom.php
git commit -m "chore: bootstrap Intercom plugin scaffolding

Adds plugin.json, config/intercom.php, and .gitignore. The plugin is
symlinked into panel/plugins/intercom for local discovery. Resolves
panel_version against the running host panel.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 1: IntercomBootPayload service (TDD)

Build the pure function that produces the identity + locale payload. This is the one unit in the plugin with non-trivial logic — every other file either delegates to it or is framework glue.

**Files:**
- Create: `tests/Unit/IntercomBootPayloadTest.php`
- Create: `src/Services/IntercomBootPayload.php`

### Task 1a: Failing test — unauthenticated returns null

- [ ] **Step 1: Write the failing test**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/tests/Unit/IntercomBootPayloadTest.php`:

```php
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
        // register the plugin's PSR-4 mapping manually. Re-registering
        // the same prefix on later tests is a no-op in composer's ClassLoader.
        // IMPORTANT: parent::setUp() must run first — base_path() requires
        // the Laravel app to be booted.
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_returns_null_when_unauthenticated
```

Expected: FAIL with `Error: Class "EzGameHostLlc\Intercom\Services\IntercomBootPayload" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/src/Services/IntercomBootPayload.php`:

```php
<?php

namespace EzGameHostLlc\Intercom\Services;

class IntercomBootPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forCurrentUser(): ?array
    {
        return null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_returns_null_when_unauthenticated
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add src/Services/IntercomBootPayload.php tests/Unit/IntercomBootPayloadTest.php
git commit -m "test(payload): null return when unauthenticated

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 1b: Failing test — blank app_id returns null

- [ ] **Step 1: Add the failing test**

Append this test method to `tests/Unit/IntercomBootPayloadTest.php` inside the class body:

```php
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
```

- [ ] **Step 2: Run the test — should still pass**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_returns_null_when_app_id_is_blank
```

Expected: PASS (because the current implementation returns `null` unconditionally). This test documents the required behavior but doesn't yet exercise logic. That's fine — the next failing test will force the implementation to fork.

- [ ] **Step 3: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add tests/Unit/IntercomBootPayloadTest.php
git commit -m "test(payload): null return when app_id is blank

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 1c: Failing test — blank identity_secret returns null

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/IntercomBootPayloadTest.php`:

```php
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
```

- [ ] **Step 2: Run the test**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_returns_null_when_identity_secret_is_blank
```

Expected: PASS (same reason as 1b).

- [ ] **Step 3: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add tests/Unit/IntercomBootPayloadTest.php
git commit -m "test(payload): null return when identity_secret is blank

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 1d: Failing test — happy path returns full payload

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/IntercomBootPayloadTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_returns_full_payload_when_authenticated_and_configured
```

Expected: FAIL with `Failed asserting that null is of type "array"`.

- [ ] **Step 3: Implement the happy path**

Replace the contents of `src/Services/IntercomBootPayload.php` with:

```php
<?php

namespace EzGameHostLlc\Intercom\Services;

class IntercomBootPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forCurrentUser(): ?array
    {
        $user = auth()->user();
        $appId = config('intercom.app_id');
        $secret = config('intercom.identity_secret');

        if (!$user || !$appId || !$secret) {
            return null;
        }

        return [
            'app_id' => $appId,
            'user_id' => $user->uuid,
            'user_hash' => hash_hmac('sha256', (string) $user->uuid, (string) $secret),
            'email' => $user->email,
            'name' => $user->username,
            'created_at' => $user->created_at?->timestamp,
            'language_override' => $user->language,
            'timezone' => $user->timezone,
        ];
    }
}
```

- [ ] **Step 4: Run all payload tests**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php
```

Expected: 4 tests pass (the unauthenticated, blank app_id, blank secret, and happy-path cases).

- [ ] **Step 5: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add src/Services/IntercomBootPayload.php tests/Unit/IntercomBootPayloadTest.php
git commit -m "feat(payload): build identity+locale payload from auth user

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 1e: Failing test — user_hash is correct HMAC

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/IntercomBootPayloadTest.php`:

```php
    public function test_user_hash_is_hmac_sha256_of_uuid(): void
    {
        $user = new \App\Models\User();
        $user->forceFill([
            'id' => 1,
            'uuid' => 'known-uuid-fixture',
            'email' => 'u@example.com',
            'username' => 'u',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', 'app');
        config()->set('intercom.identity_secret', 'known-secret');

        $payload = IntercomBootPayload::forCurrentUser();
        $expected = hash_hmac('sha256', 'known-uuid-fixture', 'known-secret');

        $this->assertSame($expected, $payload['user_hash']);
    }
```

- [ ] **Step 2: Run the test**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_user_hash_is_hmac_sha256_of_uuid
```

Expected: PASS — the implementation already computes this. The test is a behavior lock: if a future contributor changes the hash algorithm or input, it fails loudly.

- [ ] **Step 3: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add tests/Unit/IntercomBootPayloadTest.php
git commit -m "test(payload): lock in HMAC-SHA256 over uuid+secret

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 1f: Failing test — user_id is uuid, not integer id

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/IntercomBootPayloadTest.php`:

```php
    public function test_user_id_is_uuid_not_integer_id(): void
    {
        $user = new \App\Models\User();
        $user->forceFill([
            'id' => 12345,
            'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'email' => 'u@example.com',
            'username' => 'u',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

        config()->set('intercom.app_id', 'app');
        config()->set('intercom.identity_secret', 'secret');

        $payload = IntercomBootPayload::forCurrentUser();

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $payload['user_id']);
        $this->assertNotSame(12345, $payload['user_id']);
    }
```

- [ ] **Step 2: Run the test**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_user_id_is_uuid_not_integer_id
```

Expected: PASS. This is a regression guard against someone changing `user_id` to `$user->id`.

- [ ] **Step 3: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add tests/Unit/IntercomBootPayloadTest.php
git commit -m "test(payload): lock in uuid as Intercom user_id

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 1g: Failing test — whitelist regression guard

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/IntercomBootPayloadTest.php`:

```php
    public function test_payload_keys_match_whitelist_exactly(): void
    {
        $user = new \App\Models\User();
        $user->forceFill([
            'id' => 1,
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'email' => 'u@example.com',
            'username' => 'u',
            'language' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
        ]);
        $this->actingAs($user);

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
```

- [ ] **Step 2: Run the test**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php --filter test_payload_keys_match_whitelist_exactly
```

Expected: PASS. This is the privacy regression guard from spec §6.

- [ ] **Step 3: Run all payload tests together**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomBootPayloadTest.php
```

Expected: 7 tests pass (unauthenticated, blank app_id, blank secret, happy path, HMAC, uuid-not-id, whitelist).

- [ ] **Step 4: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add tests/Unit/IntercomBootPayloadTest.php
git commit -m "test(payload): lock whitelist of fields sent to Intercom

Privacy regression guard. Adding a key without updating this test
causes an immediate failure, forcing a deliberate privacy review.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: boot.blade.php view (TDD)

The view renders an empty string when the payload service returns null, and emits the Intercom JS snippet with the payload inlined when it returns an array.

**Files:**
- Create: `tests/Unit/BootViewTest.php`
- Create: `resources/views/boot.blade.php`

### Task 2a: Failing test — empty output when payload is null

- [ ] **Step 1: Write the failing test**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/tests/Unit/BootViewTest.php`:

```php
<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\View;

class BootViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // parent::setUp() must run first — base_path() requires Laravel booted.
        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));

        config()->set('intercom', require base_path('plugins/intercom/config/intercom.php'));

        // Register the plugin's view namespace since PluginService doesn't in tests.
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
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/BootViewTest.php --filter test_renders_empty_string_when_payload_is_null
```

Expected: FAIL with `InvalidArgumentException: View [intercom::boot] not found` (the view file doesn't exist yet).

- [ ] **Step 3: Create the minimal view**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/resources/views/boot.blade.php`:

```blade
@php($payload = \EzGameHostLlc\Intercom\Services\IntercomBootPayload::forCurrentUser())
@if($payload)
{{-- Populated in the next step --}}
@endif
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/BootViewTest.php --filter test_renders_empty_string_when_payload_is_null
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add resources/views/boot.blade.php tests/Unit/BootViewTest.php
git commit -m "test(view): empty render when payload is null

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 2b: Failing test — emits Intercom script with inlined payload

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/BootViewTest.php`:

```php
    public function test_renders_intercom_script_with_inlined_payload(): void
    {
        $user = new \App\Models\User();
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
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/BootViewTest.php --filter test_renders_intercom_script_with_inlined_payload
```

Expected: FAIL — the view currently emits nothing between the `@if`/`@endif`.

- [ ] **Step 3: Implement the full view**

Replace the contents of `/Users/matthewzhao/code/pelican-plugin-intercom/resources/views/boot.blade.php` with:

```blade
@php($payload = \EzGameHostLlc\Intercom\Services\IntercomBootPayload::forCurrentUser())
@if($payload)
{{-- Identity payload is built server-side in IntercomBootPayload::forCurrentUser(). --}}
{{-- Any future changes to the field set MUST update the whitelist test. See spec §6. --}}
<script>
  window.intercomSettings = {!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!};
</script>
<script>
  (function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',w.intercomSettings);}else{var d=document;var i=function(){i.c(arguments);};i.q=[];i.c=function(args){i.q.push(args);};w.Intercom=i;var l=function(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://widget.intercom.io/widget/'+{!! json_encode($payload['app_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);};if(document.readyState==='complete'){l();}else if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}})();
</script>
@endif
```

The `JSON_HEX_*` flags harden against XSS when the payload is inlined into a `<script>` tag — any `<`, `>`, `&`, `'`, or `"` in user data gets hex-encoded so it can't close the script tag or inject attributes. This is the Laravel-recommended way to inline JSON into HTML.

- [ ] **Step 4: Run both view tests**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/BootViewTest.php
```

Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add resources/views/boot.blade.php tests/Unit/BootViewTest.php
git commit -m "feat(view): render Intercom boot script with inlined payload

Uses JSON_HEX_* flags to neutralize any <, >, &, or quote characters
in user data so the inlined JSON can't break out of the <script> tag.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: IntercomPlugin class — settings form + save (TDD)

Build the Filament plugin class that exposes the three settings fields and persists them to `.env` via `EnvironmentWriterTrait`.

**Files:**
- Create: `tests/Unit/IntercomPluginSettingsTest.php`
- Create: `src/IntercomPlugin.php`

### Task 3a: Failing test — getSettingsForm returns three TextInput fields

- [ ] **Step 1: Write the failing test**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/tests/Unit/IntercomPluginSettingsTest.php`:

```php
<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use EzGameHostLlc\Intercom\IntercomPlugin;
use Filament\Forms\Components\TextInput;

class IntercomPluginSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // parent::setUp() must run first — base_path() requires Laravel booted.
        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));
    }

    public function test_settings_form_has_three_fields_with_expected_names(): void
    {
        $plugin = new IntercomPlugin();
        $fields = $plugin->getSettingsForm();

        $this->assertCount(3, $fields);

        $names = array_map(fn ($field) => $field->getName(), $fields);
        $this->assertSame(
            ['INTERCOM_APP_ID', 'INTERCOM_IDENTITY_SECRET', 'INTERCOM_API_BASE'],
            $names
        );

        foreach ($fields as $field) {
            $this->assertInstanceOf(TextInput::class, $field);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginSettingsTest.php
```

Expected: FAIL with `Error: Class "EzGameHostLlc\Intercom\IntercomPlugin" not found`.

- [ ] **Step 3: Create the plugin class**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/src/IntercomPlugin.php`:

```php
<?php

namespace EzGameHostLlc\Intercom;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Panel;

class IntercomPlugin implements Plugin, HasPluginSettings
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'intercom';
    }

    public function register(Panel $panel): void
    {
        // Intentionally empty; render hook lives in the ServiceProvider.
        // Kept as a placeholder for future per-panel extensions (widgets, pages).
    }

    public function boot(Panel $panel): void
    {
        //
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        return [
            TextInput::make('INTERCOM_APP_ID')
                ->label('App ID')
                ->placeholder('abc12345')
                ->helperText('Found in your Intercom workspace under Settings → Installation → Web.')
                ->required()
                ->default(env('INTERCOM_APP_ID')),
            TextInput::make('INTERCOM_IDENTITY_SECRET')
                ->label('Identity Verification Secret')
                ->helperText('Settings → Security → Identity Verification → Generate secret.')
                ->password()
                ->revealable()
                ->autocomplete(false)
                ->required()
                ->default(env('INTERCOM_IDENTITY_SECRET')),
            TextInput::make('INTERCOM_API_BASE')
                ->label('Widget Base URL (optional)')
                ->placeholder('https://widget.intercom.io')
                ->helperText('Override for EU/AU data-residency regions. Leave blank for default.')
                ->default(env('INTERCOM_API_BASE')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment($data);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginSettingsTest.php
```

Expected: 1 test passes. Password masking on the identity secret field is covered by manual verification Step 2.

- [ ] **Step 5: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add src/IntercomPlugin.php tests/Unit/IntercomPluginSettingsTest.php
git commit -m "feat(plugin): Filament plugin class with settings form

Implements HasPluginSettings with three TextInput fields (app_id,
identity_secret, api_base). The identity secret field is password-masked
with revealable toggle and autocomplete disabled.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

### Task 3b: Failing test — saveSettings writes to .env

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/IntercomPluginSettingsTest.php`:

```php
    public function test_save_settings_writes_all_three_keys_to_env(): void
    {
        // Use a temporary .env file so we don't clobber the test panel's real .env.
        $tmpEnv = base_path('.env.intercom-test-' . uniqid());
        file_put_contents($tmpEnv, "APP_ENV=testing\n");

        // Rebind base_path('.env') indirectly: EnvironmentWriterTrait hardcodes
        // base_path('.env'), so we back up, overwrite, and restore.
        $realEnv = base_path('.env');
        $realEnvBackup = file_exists($realEnv) ? file_get_contents($realEnv) : null;
        file_put_contents($realEnv, "APP_ENV=testing\n");

        try {
            $plugin = new IntercomPlugin();
            $plugin->saveSettings([
                'INTERCOM_APP_ID' => 'my-test-app-id',
                'INTERCOM_IDENTITY_SECRET' => 'my-test-secret',
                'INTERCOM_API_BASE' => 'https://custom.intercom.example',
            ]);

            $envContents = file_get_contents($realEnv);
            $this->assertStringContainsString('INTERCOM_APP_ID=my-test-app-id', $envContents);
            $this->assertStringContainsString('INTERCOM_IDENTITY_SECRET=my-test-secret', $envContents);
            $this->assertStringContainsString('INTERCOM_API_BASE=https://custom.intercom.example', $envContents);
        } finally {
            // Restore or remove the real .env.
            if ($realEnvBackup !== null) {
                file_put_contents($realEnv, $realEnvBackup);
            } else {
                @unlink($realEnv);
            }
            @unlink($tmpEnv);
        }
    }
```

- [ ] **Step 2: Run the test**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginSettingsTest.php --filter test_save_settings_writes_all_three_keys_to_env
```

Expected: PASS — the existing `saveSettings` implementation delegates to `writeToEnvironment`, which writes to `base_path('.env')`.

- [ ] **Step 3: Run the full plugin test suite together**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/
```

Expected: All tests from Tasks 1, 2, and 3 pass together. (Should be 7 + 2 + 2 = 11 tests.)

- [ ] **Step 4: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add tests/Unit/IntercomPluginSettingsTest.php
git commit -m "test(plugin): saveSettings writes all three keys to .env

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: IntercomPluginProvider — ServiceProvider + render hook

Build the Laravel ServiceProvider that merges plugin config and registers the Filament render hook scoped to the `server` and `app` panels. A smoke test verifies `boot()` does not throw.

**Files:**
- Create: `tests/Unit/IntercomPluginProviderTest.php`
- Create: `src/Providers/IntercomPluginProvider.php`

### Task 4a: Failing smoke test — provider boots cleanly

- [ ] **Step 1: Write the failing test**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/tests/Unit/IntercomPluginProviderTest.php`:

```php
<?php

namespace EzGameHostLlc\Intercom\Tests\Unit;

use App\Tests\TestCase;
use Composer\Autoload\ClassLoader;
use EzGameHostLlc\Intercom\Providers\IntercomPluginProvider;

class IntercomPluginProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // parent::setUp() must run first — base_path() requires Laravel booted.
        /** @var ClassLoader $classLoader */
        $classLoader = require base_path('vendor/autoload.php');
        $classLoader->addPsr4('EzGameHostLlc\\Intercom\\', base_path('plugins/intercom/src/'));
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
        // Use [] not null — mergeConfigFrom internally calls array_merge,
        // which TypeErrors when the existing config key is null on PHP 8.2+.
        config()->set('intercom', []);

        $this->app->register(IntercomPluginProvider::class);

        $this->assertNotNull(config('intercom.api_base'));
        $this->assertSame('https://widget.intercom.io', config('intercom.api_base'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginProviderTest.php
```

Expected: FAIL with `Error: Class "EzGameHostLlc\Intercom\Providers\IntercomPluginProvider" not found`.

- [ ] **Step 3: Create the ServiceProvider**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/src/Providers/IntercomPluginProvider.php`:

```php
<?php

namespace EzGameHostLlc\Intercom\Providers;

use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\ServerPanelProvider;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class IntercomPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // PluginService::loadPlugins() already does config()->set('intercom', require ...)
        // (see PluginService.php:72-74) when the plugin is loaded through the normal
        // lifecycle. mergeConfigFrom is defensive: it lets the plugin also work when
        // registered directly (e.g., in tests, or in any environment that hasn't run
        // through loadPlugins).
        $this->mergeConfigFrom(
            plugin_path('intercom', 'config', 'intercom.php'),
            'intercom'
        );
    }

    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn () => Blade::render('intercom::boot'),
            scopes: [ServerPanelProvider::class, AppPanelProvider::class],
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginProviderTest.php
```

Expected: 2 tests pass.

- [ ] **Step 5: Run the entire plugin test suite one more time**

```bash
cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/
```

Expected: 13 tests pass (7 payload + 2 view + 2 plugin settings + 2 provider).

- [ ] **Step 6: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add src/Providers/IntercomPluginProvider.php tests/Unit/IntercomPluginProviderTest.php
git commit -m "feat(provider): register Intercom render hook on server+app panels

Scopes the hook to ServerPanelProvider and AppPanelProvider so the
widget never loads on admin or unauthenticated pages. Merges plugin
config defensively for test harness compatibility.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: README.md

A user-facing install/configure guide so Pelican operators know how to install and run the plugin.

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write the README**

Create `/Users/matthewzhao/code/pelican-plugin-intercom/README.md`:

```markdown
# Pelican Intercom Plugin

Embeds the Intercom Messenger on end-user Pelican Panel pages (client area and server list) with server-side HMAC Identity Verification. Authenticated panel users can open verified support conversations with your team directly from the panel.

## Features

- Widget on the `server` (client area) and `app` (server list) panels only — never on admin pages.
- HMAC-SHA256 Identity Verification using the user's UUID (prevents impersonation via browser devtools).
- Whitelisted identity payload: `user_id` (UUID), `email`, `name` (username), `created_at`, `language`, `timezone`. Nothing else.
- Admin settings UI via the host panel's Plugins page (no env editing required).
- Zero runtime composer dependencies.

## Installation

1. Download the latest release zip from https://github.com/ezgamehost/pelican-plugin-intercom/releases and upload it via **Admin → Plugins → Import Plugin**, or clone this repo directly into `plugins/intercom/` of your Pelican install.
2. Click **Install** on the Intercom row in the plugin list. (No composer churn — this plugin has no composer dependencies.)
3. Click **Enable** once installation completes.

## Configuration

Open **Admin → Plugins → Intercom → Settings** (the gear icon). You'll need two values from your Intercom workspace:

### App ID
Go to Intercom **Settings → Installation → Web**. Copy the `app_id` shown in the JavaScript snippet.

### Identity Verification Secret
Go to Intercom **Settings → Security → Identity Verification**. Generate a secret for web and copy it. Treat this like a password — it's used to sign every user payload so Intercom can verify that requests come from your Pelican panel and not a malicious client.

### Widget Base URL (optional)
Leave blank unless you use Intercom's EU or AU data residency regions. If so, set this to the regional widget URL documented by Intercom.

## How it works

On every authenticated request to a `server` or `app` panel page, the plugin's service provider fires a Filament render hook that emits the Intercom boot script at the bottom of the page. The `window.intercomSettings` object is built server-side in PHP — so the HMAC hash is computed on a secret the browser never sees, and a malicious user can't impersonate another user by editing the page source.

The plugin does NOT:

- Call Intercom's REST API from your server (no Access Token needed).
- Send any data beyond the identity and locale whitelist.
- Receive webhooks from Intercom.
- Appear on admin pages, login pages, or password reset pages.

## Uninstalling

**Admin → Plugins → Intercom → Uninstall** removes the plugin from the registry. The Intercom widget disappears from your panel immediately. Your `.env` keys remain (so you can re-install without re-entering them); delete them manually if you want them gone.

## Support

Issues and feature requests: https://github.com/ezgamehost/pelican-plugin-intercom/issues

Direct contact: `infra@ezgamehost.com`

## License

Same license as the host Pelican Panel (AGPL-3.0-only).

## Developed by

EZ Game Host, LLC — https://ezgamehost.com
```

- [ ] **Step 2: Commit**

```bash
cd /Users/matthewzhao/code/pelican-plugin-intercom
git add README.md
git commit -m "docs: add README with install, config, and support info

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Full manual verification

Execute the manual verification checklist from spec §9 against a running Pelican panel. This catches integration issues that unit tests can't see — the render hook actually firing on real pages, the settings slide-over actually rendering, etc.

**No files created or modified in this task.** Report any failures as follow-up bugs in the plugin repo.

- [ ] **Step 1: Install the plugin via artisan**

```bash
cd /Users/matthewzhao/code/panel && php artisan p:plugin:install intercom
```

Expected: `Plugin installed successfully` (or equivalent). No composer output (we have no packages). No migration output (we have no migrations).

- [ ] **Step 2: Verify the settings slide-over renders**

Log into the panel's Admin area at `http://localhost` (or wherever your dev panel runs). Navigate to **Admin → Plugins**. Find the Intercom row. Click the gear (Settings) icon.

Expected: a slide-over modal appears with three fields:
- `App ID`
- `Identity Verification Secret` (password-masked with a reveal toggle)
- `Widget Base URL (optional)`

- [ ] **Step 3: Save real test credentials**

In the slide-over, enter:
- `App ID`: use a real Intercom `app_id` from your test Intercom workspace (or `test-app-id` if just smoke-testing without a real Intercom account)
- `Identity Verification Secret`: paste the matching secret (or any non-empty string if just smoke-testing)
- `Widget Base URL`: leave blank

Click **Save**.

- [ ] **Step 4: Verify .env and config are updated**

```bash
cd /Users/matthewzhao/code/panel && grep '^INTERCOM_' .env && php artisan tinker --execute="echo config('intercom.app_id');"
```

Expected: three `INTERCOM_*` lines in `.env`, and `config('intercom.app_id')` prints the value you entered.

- [ ] **Step 5: Verify widget appears on the client-area panel**

Navigate to `http://localhost/server` (or any per-server page) while logged in as a non-admin user (or any authenticated user, since the plugin doesn't check roles). View page source.

Expected:
- A `<script>` tag containing `window.intercomSettings = {...}` appears in the source.
- The JSON contains `app_id`, `user_id` (a UUID, not an integer), `user_hash` (a 64-character hex string), `email`, `name`, `created_at`, `language_override`, and `timezone`.
- A second `<script>` tag containing the Intercom widget loader appears immediately after.

If using a real Intercom app_id, the chat bubble should appear in the bottom-right corner.

- [ ] **Step 6: Verify widget is absent from the admin panel**

Navigate to `http://localhost/admin`. View page source.

Expected: no `window.intercomSettings` script tag. The `scopes` filter in `IntercomPluginProvider::boot()` should exclude admin pages.

- [ ] **Step 7: Verify widget is absent from unauthenticated pages**

Log out. Navigate to `http://localhost/login`. View page source.

Expected: no `window.intercomSettings` script tag. Log in again before the next step.

- [ ] **Step 8: Verify graceful no-op when misconfigured**

In `/Users/matthewzhao/code/panel/.env`, blank out `INTERCOM_APP_ID`:
```
INTERCOM_APP_ID=
```

Run:
```bash
cd /Users/matthewzhao/code/panel && php artisan config:clear
```

Refresh a client-area page and view source.

Expected: no `window.intercomSettings` script tag. No JavaScript errors in the browser console. The panel itself works normally.

Restore the real `INTERCOM_APP_ID` value in `.env` and run `config:clear` again before finishing.

- [ ] **Step 9: Verify uninstall is clean**

```bash
cd /Users/matthewzhao/code/panel && php artisan p:plugin:uninstall intercom
```

Navigate to a client-area page. View source.

Expected: no `window.intercomSettings` script tag. The plugin row in **Admin → Plugins** shows status `not-installed`. The symlink at `panel/plugins/intercom` is untouched (uninstall does not delete files unless `--delete-files` is passed).

Re-install before finishing: `php artisan p:plugin:install intercom`.

- [ ] **Step 10: Final commit (if any cleanup happened)**

If Task 6 surfaced no bugs, no commit is needed. If it surfaced any issues, file them in the plugin repo's issue tracker and fix them as follow-up tasks before declaring the plugin shippable.

---

## Completion checklist

Before declaring the plugin done:

- [ ] All 13 automated tests pass: `cd /Users/matthewzhao/code/panel && ./vendor/bin/pest plugins/intercom/tests/Unit/`
- [ ] Manual verification Steps 1–9 all pass
- [ ] `git log --oneline` in the plugin repo shows a clean, atomic history of TDD commits
- [ ] The plugin repo's working tree is clean (`git status` shows nothing pending)
- [ ] README.md renders correctly on GitHub (or wherever the repo is hosted)
