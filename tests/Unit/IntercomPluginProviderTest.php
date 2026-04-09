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
        // Use an empty array (not null) because mergeConfigFrom calls array_merge()
        // internally and array_merge() requires an array, not null.
        config()->set('intercom', []);

        $this->app->register(IntercomPluginProvider::class);

        $this->assertNotNull(config('intercom.api_base'));
        $this->assertSame('https://widget.intercom.io', config('intercom.api_base'));
    }
}
