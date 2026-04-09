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
        // Intentionally empty; no per-panel boot work is needed.
    }

    /**
     * @return \Filament\Schemas\Components\Component[]
     */
    public function getSettingsForm(): array
    {
        // Reading defaults from config() (not env()) so they survive
        // `php artisan config:cache` in production deployments.
        // saveSettings() writes .env + calls config:clear, so the next
        // render picks up fresh values through this same config path.
        return [
            TextInput::make('INTERCOM_APP_ID')
                ->label('App ID')
                ->placeholder('abc12345')
                ->helperText('Found in your Intercom workspace under Settings → Installation → Web.')
                ->required()
                ->default(config('intercom.app_id')),
            TextInput::make('INTERCOM_IDENTITY_SECRET')
                ->label('Identity Verification Secret')
                ->helperText('Settings → Security → Identity Verification → Generate secret.')
                ->password()
                ->revealable()
                ->autocomplete(false)
                ->required()
                ->default(config('intercom.identity_secret')),
            TextInput::make('INTERCOM_API_BASE')
                ->label('Widget Base URL (optional)')
                ->placeholder('https://widget.intercom.io')
                ->helperText('Override for EU/AU data-residency regions. Leave blank for default.')
                ->default(config('intercom.api_base')),
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
