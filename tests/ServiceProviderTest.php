<?php

namespace LocalDynamics\Revisionable\Tests;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use LocalDynamics\Revisionable\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends TestCase
{
    #[Test]
    public function package_config_is_merged()
    {
        // The package config is merged in register(), so its keys are
        // available without publishing first.
        $this->assertTrue(config('revisionable.enabled'));
    }

    #[Test]
    public function publishable_source_paths_exist()
    {
        $paths = BaseServiceProvider::pathsToPublish(ServiceProvider::class);

        $this->assertNotEmpty($paths);

        foreach (array_keys($paths) as $source) {
            $this->assertFileExists($source);
        }
    }
}
