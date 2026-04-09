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
