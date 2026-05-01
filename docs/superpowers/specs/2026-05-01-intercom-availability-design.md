# Intercom Availability on Per-Server Pages

## Goal

Make Intercom available anywhere an authenticated end user works in Pelican's client-facing panels, including the server list and every page inside a selected server.

The plugin should rely on Intercom's own Messenger launcher. It should not add a Pelican or Filament header button.

## Current Context

The plugin already registers a Filament `PanelsRenderHook::SCRIPTS_AFTER` hook in `IntercomPluginProvider` and renders `intercom::boot`.

The provider currently allows these panel IDs:

- `app`: the server list/client area.
- `server`: the selected-server panel.

The boot view builds `window.intercomSettings` from `IntercomBootPayload::forCurrentUser()` and loads the Intercom widget script. The payload is server-built, HMAC-signed, and intentionally limited to identity and locale fields.

## Design

Keep the integration script-only.

`IntercomPluginProvider` should continue to render the boot view on authenticated `app` and `server` panel pages and reject other panels, especially `admin`.

No custom header action should be introduced. The user's intended "button" is Intercom's own launcher, which appears once the boot script loads successfully.

The identity payload remains unchanged. This follow-up must not add server UUIDs, server names, admin role data, permissions, usage metrics, or other panel-domain fields to Intercom.

## Behavior

When the plugin is configured and an authenticated user visits:

- the server list in the `app` panel, Intercom boots.
- any page inside a selected server in the `server` panel, Intercom boots.
- an admin panel page, Intercom does not boot.
- an auth or password-reset page, Intercom does not boot because no verified user payload exists.

When the plugin is missing required config or there is no authenticated user, the boot view renders nothing and the panel keeps working normally.

## Testing

Add or adjust provider-level tests so the allowed-panel behavior is explicit:

- `app` panel returns the rendered boot view.
- `server` panel returns the rendered boot view.
- `admin` panel returns an empty string.

Keep existing boot-view and payload tests as the privacy and script-safety guards.

## Documentation

Update the README if needed to clarify that Intercom is available through its own launcher on both the server list and all per-server pages, not through a custom Pelican button.
