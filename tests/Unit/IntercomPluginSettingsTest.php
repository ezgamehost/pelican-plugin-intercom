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

    public function test_save_settings_writes_all_three_keys_to_env(): void
    {
        // We write to the panel's real .env. Back it up and restore after.
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
            // Env::writeVariables always wraps values in double quotes.
            $this->assertStringContainsString('INTERCOM_APP_ID="my-test-app-id"', $envContents);
            $this->assertStringContainsString('INTERCOM_IDENTITY_SECRET="my-test-secret"', $envContents);
            $this->assertStringContainsString('INTERCOM_API_BASE="https://custom.intercom.example"', $envContents);
        } finally {
            // Restore or remove the real .env.
            if ($realEnvBackup !== null) {
                file_put_contents($realEnv, $realEnvBackup);
            } else {
                @unlink($realEnv);
            }
        }
    }
}
