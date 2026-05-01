# Intercom Availability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the existing Intercom Messenger bootstrap script actually render on authenticated `app` and `server` panel pages, including every page inside a selected server.

**Architecture:** Keep the integration script-only. Extract the provider hook callback into a testable method, render the namespaced Blade view with Laravel's view renderer, and preserve the existing panel allow-list so admin/auth pages stay excluded.

**Tech Stack:** Laravel service provider, Filament panel/render hooks, Blade views, PHPUnit/Pest tests run from the host Pelican panel.

---

## Context

The plugin repo is `/Users/matthewzhao/code/pelican-plugin-intercom`.

The host panel repo is `/Users/matthewzhao/code/panel`.

The host panel discovers this plugin through `panel/plugins/intercom`, which should be a symlink to the plugin repo. Tests must be run from the panel root because they need the panel's Laravel bootstrap and Composer autoload.

Spec: `docs/superpowers/specs/2026-05-01-intercom-availability-design.md`

Important current behavior:

- `src/Providers/IntercomPluginProvider.php` already allows panel IDs `app` and `server`.
- The provider currently calls `Blade::render('intercom::boot')`. That renders inline Blade text, not the namespaced view. The hook should render `view('intercom::boot')->render()` instead.
- `resources/views/boot.blade.php` already no-ops when unauthenticated or misconfigured.

## File Structure

Modify:

- `src/Providers/IntercomPluginProvider.php` - extract the hook callback into a testable method and render the real view.
- `tests/Unit/IntercomPluginProviderTest.php` - add provider behavior tests for `app`, `server`, and `admin` panel IDs.
- `README.md` - clarify that Intercom is available through its own launcher on the server list and all per-server pages.

Do not modify:

- `resources/views/boot.blade.php` - existing payload/script behavior is already covered by view tests.
- `src/Services/IntercomBootPayload.php` - the identity payload must remain unchanged.
- Host panel files in `/Users/matthewzhao/code/panel` - this should be a plugin-only fix.

## Task 1: Provider Panel Availability Tests

**Files:**

- Modify: `tests/Unit/IntercomPluginProviderTest.php`

- [ ] **Step 1: Add test helpers and imports**

Update `tests/Unit/IntercomPluginProviderTest.php` imports:

```php
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\View;
```

In `setUp()`, after the existing PSR-4 setup, register the plugin view namespace and default config:

```php
config()->set('intercom', require base_path('plugins/intercom/config/intercom.php'));
View::addNamespace('intercom', base_path('plugins/intercom/resources/views'));
```

Add helper methods inside the test class:

```php
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
```

- [ ] **Step 2: Add failing tests for allowed and rejected panels**

Append these tests:

```php
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
```

- [ ] **Step 3: Run the provider test and verify it fails**

Run from `/Users/matthewzhao/code/panel`:

```bash
./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginProviderTest.php --filter provider_renders_boot_view_on_app_panel
```

Expected: FAIL with `Call to undefined method EzGameHostLlc\Intercom\Providers\IntercomPluginProvider::renderBootScriptForCurrentPanel()`.

Do not implement yet if this test does not fail for that reason.

## Task 2: Render the Real Boot View from the Provider

**Files:**

- Modify: `src/Providers/IntercomPluginProvider.php`
- Test: `tests/Unit/IntercomPluginProviderTest.php`

- [ ] **Step 1: Replace the hook callback with a testable method**

Update `src/Providers/IntercomPluginProvider.php`:

```php
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;
```

Remove the unused `Illuminate\Support\Facades\Blade` import.

Change `boot()` to:

```php
public function boot(): void
{
    // Filament's renderHook scopes match against Page class names at render
    // time (see BasePage::getRenderHookScopes), not PanelProvider classes -
    // so panel-level filtering must happen inside the hook itself.
    FilamentView::registerRenderHook(
        PanelsRenderHook::SCRIPTS_AFTER,
        fn (): string => $this->renderBootScriptForCurrentPanel(),
    );
}
```

Add this public method below `boot()`:

```php
public function renderBootScriptForCurrentPanel(): string
{
    $panelId = Filament::getCurrentPanel()?->getId();

    if (!in_array($panelId, self::ALLOWED_PANELS, true)) {
        return '';
    }

    return (string) view('intercom::boot')->render();
}
```

- [ ] **Step 2: Run provider tests**

Run from `/Users/matthewzhao/code/panel`:

```bash
./vendor/bin/pest plugins/intercom/tests/Unit/IntercomPluginProviderTest.php
```

Expected: PASS. The three new tests prove `app` and `server` render Intercom while `admin` does not.

- [ ] **Step 3: Run the full plugin unit suite**

Run from `/Users/matthewzhao/code/panel`:

```bash
./vendor/bin/pest plugins/intercom/tests/Unit/
```

Expected: PASS. Existing payload and boot-view tests must continue passing unchanged.

- [ ] **Step 4: Commit provider fix**

Run from `/Users/matthewzhao/code/pelican-plugin-intercom`:

```bash
git add src/Providers/IntercomPluginProvider.php tests/Unit/IntercomPluginProviderTest.php
git commit -m "fix(provider): render intercom boot view on user panels"
```

## Task 3: README Clarification

**Files:**

- Modify: `README.md`

- [ ] **Step 1: Update the feature and behavior wording**

Change the opening paragraph to clarify per-server coverage:

```markdown
Embeds the Intercom Messenger on end-user Pelican Panel pages (the server list and every per-server page) with server-side HMAC Identity Verification. Authenticated panel users can open verified support conversations with your team directly from the panel through Intercom's own launcher.
```

Change the first feature bullet to:

```markdown
- Widget on the `app` (server list) and `server` (all per-server pages) panels only - never on admin pages.
```

Change the first paragraph under "How it works" to:

```markdown
On every authenticated request to an `app` or `server` panel page, the plugin's service provider fires a Filament render hook that emits the Intercom boot script at the bottom of the page. Intercom's own Messenger launcher is the visible support control. The `window.intercomSettings` object is built server-side in PHP - so the HMAC hash is computed on a secret the browser never sees, and a malicious user can't impersonate another user by editing the page source.
```

- [ ] **Step 2: Review README diff**

Run from `/Users/matthewzhao/code/pelican-plugin-intercom`:

```bash
git diff -- README.md
```

Expected: wording-only changes. No installation or configuration steps should change.

- [ ] **Step 3: Commit docs update**

Run from `/Users/matthewzhao/code/pelican-plugin-intercom`:

```bash
git add README.md
git commit -m "docs: clarify intercom availability on server pages"
```

## Task 4: Final Verification

**Files:**

- Verify all changed files.

- [ ] **Step 1: Run the full plugin unit suite**

Run from `/Users/matthewzhao/code/panel`:

```bash
./vendor/bin/pest plugins/intercom/tests/Unit/
```

Expected: PASS.

- [ ] **Step 2: Run formatting if Pint is available**

Run from `/Users/matthewzhao/code/panel`:

```bash
./vendor/bin/pint plugins/intercom/src/Providers/IntercomPluginProvider.php plugins/intercom/tests/Unit/IntercomPluginProviderTest.php
```

Expected: PASS or formatted files with no syntax errors.

- [ ] **Step 3: Check git status**

Run from `/Users/matthewzhao/code/pelican-plugin-intercom`:

```bash
git status --short
```

Expected: clean working tree after the two implementation commits.

- [ ] **Step 4: Optional manual smoke test**

In a running Pelican panel with the plugin enabled and configured:

1. Visit the server list in the `app` panel.
2. View page source and confirm `window.intercomSettings` is present.
3. Visit a selected server's Console page in the `server` panel.
4. View page source and confirm `window.intercomSettings` is present.
5. Visit an admin page.
6. Confirm `window.intercomSettings` is absent.

Expected: Intercom's own launcher is available on the server list and per-server pages only.
