# Pelican Panel — Intercom Support Plugin

**Date:** 2026-04-08
**Author:** EZ Game Host, LLC `<infra@ezgamehost.com>`
**Status:** Design approved — ready for implementation planning
**Target:** Pelican Panel plugin, publicly redistributable

## 1. Purpose

Embed the Intercom Messenger widget into end-user-facing Pelican Panel pages (client area and server list) with server-side Identity Verification so authenticated panel users can open verified support conversations without leaving the panel. A minimal, polished, publishable plugin any Pelican operator can install by providing their Intercom `app_id` and identity-verification secret.

## 2. Goals and non-goals

### Goals
- Display the Intercom widget on the `server` (client area) and `app` (server list) Filament panels.
- Send a server-built, HMAC-signed identity payload on every authenticated page load so Intercom knows exactly who the panel user is and cannot be impersonated from the browser.
- Provide a Filament admin settings slide-over for configuring `INTERCOM_APP_ID`, `INTERCOM_IDENTITY_SECRET`, and an optional regional widget base URL.
- Follow Pelican's standard plugin conventions (`plugin.json`, `PluginProvider`, `config/<id>.php`, `HasPluginSettings`) so installation, enable/disable, and uninstall all go through the existing `PluginService` lifecycle with zero custom glue.
- Zero runtime composer dependencies — the plugin relies only on what Pelican already ships.

### Non-goals (v1)
- Inbound webhooks from Intercom (conversation events).
- Server-side Intercom REST API calls (offline attribute sync, events API, contact upserts).
- Custom attributes for role, permissions, server count, subscription, or any other Pelican domain data beyond identity + locale.
- Admin-side integration (deep links from Intercom to Pelican admin user pages, in-panel support inboxes).
- Widget display on unauthenticated pages (login, register, password reset) or on the admin panel.
- Localization beyond passing `language_override` to Intercom's own widget.

Each non-goal is a legitimate future extension but excluded from v1 to keep the plugin's failure surface small.

## 3. Background: Pelican's plugin system

Pelican's plugin system is file-system discovered and lives under `plugins/<id>/`. Relevant mechanics, with file citations into the host panel at `~/code/panel`:

- **Discovery:** `app/Models/Plugin.php:119` scans `base_path('plugins/')` on boot and reads each `plugin.json`. The folder name must equal `id`.
- **Class loading:** `app/Services/Helpers/PluginService.php:62-68` registers PSR-4 roots for `<namespace>\` → `plugins/<id>/src/`, plus `Database\Factories\` and `Database\Seeders\`.
- **Config:** `PluginService.php:70-74` auto-loads `plugins/<id>/config/<id>.php` into Laravel's config under the plugin id.
- **Providers / migrations / views / lang:** all auto-registered when `plugin.shouldLoad()` is true (`PluginService.php:82-127`).
- **Per-panel registration:** `PluginService::loadPanelPlugins()` (line 134) instantiates each enabled plugin's Filament `Plugin` class and calls `$panel->plugin(...)`, respecting the `panels` whitelist in `plugin.json`.
- **Settings UI:** plugins implementing `App\Contracts\Plugins\HasPluginSettings` get an auto-rendered slide-over action on the Admin → Plugins page (`app/Filament/Admin/Resources/Plugins/PluginResource.php:109-117`). The interface is two methods: `getSettingsForm(): Component[]` and `saveSettings(array $data): void`.
- **Env writes:** the canonical way to persist settings on the panel is `App\Traits\EnvironmentWriterTrait::writeToEnvironment()` (`app/Traits/EnvironmentWriterTrait.php:18`), which calls `Env::writeVariables()` and then `Artisan::call('config:clear')`.
- **Render hooks:** Filament v4's `FilamentView::registerRenderHook($hook, $callback, scopes: [...])` is the mechanism for injecting content at fixed points in the panel chrome. The host panel already uses it at `app/Providers/Filament/FilamentServiceProvider.php:72` to inject the Vite-compiled app JS via `PanelsRenderHook::SCRIPTS_AFTER`.
- **Plugin scaffolding:** `php artisan p:plugin:make` (at `app/Console/Commands/Plugin/MakePluginCommand.php`) produces the canonical layout. The command sanitizes the author name via `preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii(...))` at line 43, so punctuation in the author string is stripped before it becomes the PHP namespace.

## 4. Plugin identity and layout

**Scaffolding inputs:**

| Field | Value |
|---|---|
| `name` | `Intercom` |
| `id` (folder name) | `intercom` |
| `author` (post-sanitization) | `EZ Game Host LLC` |
| `namespace` | `EzGameHostLlc\Intercom` |
| `class` | `IntercomPlugin` |
| `category` | `plugin` |
| `panels` | `["server", "app"]` |
| `panel_version` | Resolve at scaffold time: read `config('app.version')` from the host panel and use that as a `^`-prefixed constraint (matching `MakePluginCommand.php:87-91`). If the panel reports `canary`, leave unset. |
| `composer_packages` | `null` |
| `url` | `https://github.com/ezgamehost/pelican-plugin-intercom` |
| `update_url` | `https://raw.githubusercontent.com/ezgamehost/pelican-plugin-intercom/main/updates.json` |

**On-disk layout:**

```
plugins/intercom/
├── plugin.json
├── README.md
├── config/
│   └── intercom.php
├── src/
│   ├── IntercomPlugin.php                 # Filament\Contracts\Plugin + HasPluginSettings
│   ├── Providers/
│   │   └── IntercomPluginProvider.php     # Laravel ServiceProvider; registers render hook
│   └── Services/
│       └── IntercomBootPayload.php        # builds the identity + attributes payload
├── resources/
│   └── views/
│       └── boot.blade.php                 # renders the Intercom JS snippet
└── tests/
    └── Unit/
        ├── IntercomBootPayloadTest.php
        ├── IntercomPluginSettingsTest.php
        └── BootViewTest.php
```

No migrations, no routes, no JS bundle, no Livewire components. One Blade view, one service, one provider, one plugin class, one config file.

**`plugin.json`:**

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
  "panel_version": "<resolved from config('app.version') at scaffold time>",
  "composer_packages": null,
  "meta": {
    "status": "not-installed",
    "status_message": null
  }
}
```

## 5. Configuration surface

**Environment variables** (persisted via `writeToEnvironment()`):

| Key | Required | Purpose |
|---|---|---|
| `INTERCOM_APP_ID` | yes | Public Intercom workspace app ID; becomes the widget's `app_id`. |
| `INTERCOM_IDENTITY_SECRET` | yes | Shared secret for HMAC-SHA256 Identity Verification. Server-side only. |
| `INTERCOM_API_BASE` | no | Override for EU/AU data-residency regions. Defaults to `https://widget.intercom.io`. |

**`config/intercom.php`:**

```php
<?php

return [
    'app_id' => env('INTERCOM_APP_ID'),
    'identity_secret' => env('INTERCOM_IDENTITY_SECRET'),
    'api_base' => env('INTERCOM_API_BASE', 'https://widget.intercom.io'),
];
```

**Filament settings form** (rendered as slide-over on Admin → Plugins via `HasPluginSettings`):

- `INTERCOM_APP_ID` — `TextInput`, required, helper text points to Intercom's Installation → Web settings.
- `INTERCOM_IDENTITY_SECRET` — `TextInput->password()->revealable()->autocomplete(false)`, required, helper text points to Intercom's Identity Verification settings.
- `INTERCOM_API_BASE` — `TextInput`, optional, placeholder `https://widget.intercom.io`.

`saveSettings()` delegates directly to `EnvironmentWriterTrait::writeToEnvironment($data)`, which writes all three keys to `.env` and clears the Laravel config cache.

**Misconfiguration handling:** if either `INTERCOM_APP_ID` or `INTERCOM_IDENTITY_SECRET` is blank at render time, `IntercomBootPayload::forCurrentUser()` returns `null` and `boot.blade.php` emits an empty string. No JS errors, no console warnings. The admin sees the plugin enabled but no widget until they fill in the settings.

## 6. Runtime data flow

On every request to a `server` or `app` panel page:

### Step 1 — Render hook fires

`IntercomPluginProvider::boot()` has already registered:

```php
FilamentView::registerRenderHook(
    PanelsRenderHook::SCRIPTS_AFTER,
    fn () => Blade::render('intercom::boot'),
    scopes: [ServerPanelProvider::class, AppPanelProvider::class],
);
```

The `scopes` argument restricts the hook to the two end-user panel providers. The widget never renders on admin pages, login pages, or installer pages.

### Step 2 — Blade view builds the payload

`resources/views/boot.blade.php`:

```blade
@php($payload = \EzGameHostLlc\Intercom\Services\IntercomBootPayload::forCurrentUser())
@if($payload)
<script>
  window.intercomSettings = @json($payload);
</script>
<script>
  (function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',w.intercomSettings);}else{var d=document;var i=function(){i.c(arguments);};i.q=[];i.c=function(args){i.q.push(args);};w.Intercom=i;var l=function(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src='https://widget.intercom.io/widget/'+@json($payload['app_id']);var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);};if(document.readyState==='complete'){l();}else if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}})();
</script>
@endif
```

The `reattach_activator` branch handles Livewire `wire:navigate` transitions inside Filament — on subsequent partial navigations, the already-loaded Intercom widget is re-attached rather than re-downloaded.

### Step 3 — Payload service builds the identity object

`src/Services/IntercomBootPayload.php`:

```php
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
            'app_id'            => $appId,
            'user_id'           => $user->uuid,
            'user_hash'         => hash_hmac('sha256', $user->uuid, $secret),
            'email'             => $user->email,
            'name'              => $user->username,
            'created_at'        => $user->created_at?->timestamp,
            'language_override' => $user->language,
            'timezone'          => $user->timezone,
        ];
    }
}
```

### Key field decisions

- **`user_id = $user->uuid`**, not the integer `id`. UUIDs are stable across migrations, don't leak sequential user counts, and match Pelican's API conventions. The HMAC is computed over the same UUID string so the client and server agree on identity.
- **`name = $user->username`**. Pelican's `User` model has no first/last name split; `username` is the canonical display handle. Email is sent separately.
- **`created_at`** is sent as a Unix timestamp integer (Intercom's documented format for `created_at`). Carbon's `->timestamp` accessor produces this.
- **`language_override`** is Intercom's standard key for forcing widget UI language; Pelican's `$user->language` holds an ISO language code.
- **`timezone`** is a non-standard custom attribute — it appears in the Intercom contact profile under "Custom Attributes".

### Field whitelist as single source of truth

The returned array from `forCurrentUser()` is the complete contract for what leaves the panel. Adding a field is a deliberate code change, not a config toggle. A regression test in `IntercomBootPayloadTest` asserts the set of keys exactly matches this whitelist.

## 7. Service provider and plugin class wiring

### `src/Providers/IntercomPluginProvider.php`

```php
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

The render hook is registered in the Laravel ServiceProvider (not the Filament plugin class) because render hooks are panel-global state — registering them once at boot time is cheaper than repeating the registration inside every per-panel `register(Panel)` call. The `scopes` argument handles panel filtering.

`mergeConfigFrom` is belt-and-braces: `PluginService.php:72-74` already does `config()->set('intercom', require $config)`, but `mergeConfigFrom` makes the plugin also work when loaded via raw service-provider registration (e.g., in tests — see §9).

### `src/IntercomPlugin.php`

```php
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
        // Kept as a placeholder so per-panel extensions (widgets, pages) can be added later.
    }

    public function boot(Panel $panel): void
    {
        //
    }

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

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment($data);
    }
}
```

## 8. Security, privacy, and failure modes

### Identity Verification is mandatory
The plugin refuses to boot the widget unless **both** `INTERCOM_APP_ID` and `INTERCOM_IDENTITY_SECRET` are set. There is no unverified fallback. Without HMAC, any authenticated Pelican user could edit `window.intercomSettings` in browser devtools and impersonate any other user's Intercom conversation — a confidentiality bug for a support tool. The plugin treats missing-secret as misconfiguration and silently no-ops.

### Secret handling
- `INTERCOM_IDENTITY_SECRET` lives only in `.env`, the Filament form, and server-side `hash_hmac` calls.
- The settings form uses `->password()->revealable()->autocomplete(false)` to mask the field and prevent browser autofill caching.
- HMAC is computed fresh on every render — no caching of the hash itself. `config()` values are read from Laravel's config cache, which is fine.
- The secret is never rendered into Blade output, never logged, never sent to the client.

### PII whitelist

**Sent to Intercom per page load:**
- `user.uuid`, `user.email`, `user.username`, `user.created_at` (timestamp), `user.language`, `user.timezone`

**Explicitly not sent:**
- Integer `user.id` (use UUID for stability and to avoid leaking counts)
- `user.password`, `remember_token`, MFA secrets, API tokens
- Role and permission data (scoped out in §2)
- Server list, server UUIDs, usage metrics
- `user.external_id`, `user.oauth` (may contain third-party tokens)

The `IntercomBootPayload::forCurrentUser()` return array is the single source of truth; adding a field is a code change guarded by a regression test.

### Unauthenticated request safety
The render hook's `scopes` filter restricts it to `ServerPanelProvider` and `AppPanelProvider`, both of which require authentication. Additionally, `IntercomBootPayload::forCurrentUser()` returns `null` when `auth()->user()` is null as a belt-and-braces check. Net effect: widget never appears on login/register/password-reset pages and no payload is ever built for a guest.

### Failure modes

| Condition | Behavior | Visibility |
|---|---|---|
| `INTERCOM_APP_ID` blank | Render hook emits empty string; widget doesn't boot | Plugin enabled but widget absent; admin sees empty field in slide-over |
| `INTERCOM_IDENTITY_SECRET` blank | Same | Same |
| Intercom widget CDN unreachable | Intercom snippet's own silent error handling | Users report missing chat bubble; panel keeps working |
| `user.uuid` or `user.email` null (shouldn't happen — schema NOT NULL) | Payload builder returns fields as-is; Intercom client-side `update` call fails | Client console error only; no panel-side impact |

**Deliberately not doing:**
- No server-side retry/queuing for Intercom calls (there are none).
- No graceful-degradation banner when the widget is unreachable.
- No rate-limiting on HMAC computation (single call per render, trivially cheap).

## 9. Testing strategy

### What we test

**`IntercomBootPayload` (unit tests, `tests/Unit/IntercomBootPayloadTest.php`):**
- Returns `null` when unauthenticated.
- Returns `null` when `app_id` is blank.
- Returns `null` when `identity_secret` is blank.
- Returns a payload with all whitelisted fields when authenticated and configured.
- `user_hash` equals `hash_hmac('sha256', $uuid, $secret)` for a known fixture.
- `user_id` is the UUID, never the integer id.
- `created_at` is a Unix integer timestamp, not an ISO string.
- Returned array keys exactly match the whitelist — regression guard that fails if a future contributor adds a sensitive field.

**`IntercomPlugin::saveSettings` (integration, `tests/Unit/IntercomPluginSettingsTest.php`):**
- Verifies delegation to `writeToEnvironment` and that the three keys land in `.env`.

**`boot.blade.php` (view rendering, `tests/Unit/BootViewTest.php`):**
- Given a stubbed payload, the rendered output contains `window.intercomSettings = {...}` with the expected JSON and no unescaped characters.
- Given a null payload, rendered output is an empty string.

### What we don't test
- Filament's render hook plumbing (framework code, trusted).
- The auto-rendered `HasPluginSettings` slide-over (trusted at `PluginResource.php:109-117`).
- Live Intercom API calls (plugin makes none).
- End-to-end browser tests of the actual widget appearing — covered by the manual verification checklist below.
- Pelican cross-version compatibility (handled by the `panel_version` constraint).

### Test harness gotcha

`PluginService.php:35-37` early-returns during unit tests, so plugins are NOT auto-registered in the host panel's test environment. Tests that depend on the render hook must manually register the provider:

```php
$this->app->register(\EzGameHostLlc\Intercom\Providers\IntercomPluginProvider::class);
```

A base `tests/TestCase.php` (or a Pest `beforeEach`) should do this once per test file that needs it. Tests that exercise only `IntercomBootPayload` can skip this entirely because the service is a pure function of `auth()` + `config()`.

Tests run via the host panel's `./vendor/bin/pest` since the plugin depends on Pelican's `User` model, `auth()`, `config()`, Filament components, and `EnvironmentWriterTrait`. Testing standalone would require reimplementing half of Laravel.

### Manual verification checklist

1. `php artisan p:plugin:install intercom` succeeds (no composer churn — `composer_packages` is null).
2. Admin → Plugins shows Intercom with a Settings action; the slide-over renders with three fields.
3. Save settings → `.env` contains the three keys; `config('intercom.app_id')` returns the new value after `config:clear`.
4. Navigate to the server panel → view page source → `window.intercomSettings` script tag present, JSON matches whitelist, `user_hash` is non-empty.
5. Navigate to the admin panel → script tag is absent.
6. Navigate to `/login` as a guest → script tag is absent.
7. Blank either env key and refresh the server panel → script tag is absent, no JS errors.

## 10. Future work (explicitly out of v1)

Each of these is a legitimate extension point but deferred to keep v1's failure surface small:

- Receiving inbound webhooks from Intercom (`conversation.user.replied`, etc.) via a plugin-registered route and signature verification.
- Server-side Intercom REST API calls to sync attributes when users are offline. Requires storing an Intercom Access Token and managing its rotation.
- Custom attributes for server count, role, subscription tier, and other Pelican domain data.
- Admin-side deep links from Intercom conversations back to Pelican admin user pages (via custom attribute with URL).
- Localization beyond `language_override`.
- Analytics / event tracking via the Intercom Events API (`server_created`, `payment_failed`, etc.).
- Visitor-mode widget on unauthenticated pages (login, register) for prospective users.

## 11. File-by-file summary

| Path | Purpose |
|---|---|
| `plugins/intercom/plugin.json` | Plugin metadata consumed by `PluginService` |
| `plugins/intercom/README.md` | Install, configure, support contact (`infra@ezgamehost.com`) |
| `plugins/intercom/config/intercom.php` | Maps env vars to `config('intercom.*')` |
| `plugins/intercom/src/IntercomPlugin.php` | Filament `Plugin` + `HasPluginSettings` implementation |
| `plugins/intercom/src/Providers/IntercomPluginProvider.php` | Laravel `ServiceProvider`; registers the render hook |
| `plugins/intercom/src/Services/IntercomBootPayload.php` | Pure function building the identity + attributes payload |
| `plugins/intercom/resources/views/boot.blade.php` | Renders the Intercom JS snippet with the payload inlined |
| `plugins/intercom/tests/Unit/IntercomBootPayloadTest.php` | Payload unit tests + field whitelist regression guard |
| `plugins/intercom/tests/Unit/IntercomPluginSettingsTest.php` | `saveSettings` → `.env` integration test |
| `plugins/intercom/tests/Unit/BootViewTest.php` | Blade view rendering test |
