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
