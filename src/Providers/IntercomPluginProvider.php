<?php

namespace EzGameHostLlc\Intercom\Providers;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class IntercomPluginProvider extends ServiceProvider
{
    private const ALLOWED_PANELS = ['server', 'app'];

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
        // Filament's renderHook scopes match against Page class names at render
        // time (see BasePage::getRenderHookScopes), not PanelProvider classes —
        // so panel-level filtering must happen inside the hook itself.
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn (): string => $this->renderBootScriptForCurrentPanel(),
        );
    }

    public function renderBootScriptForCurrentPanel(): string
    {
        $panelId = Filament::getCurrentPanel()?->getId();

        if (!in_array($panelId, self::ALLOWED_PANELS, true)) {
            return '';
        }

        return (string) view('intercom::boot')->render();
    }
}
