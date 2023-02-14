<?php

namespace LocalDynamics\Revisionable\Tests;

use LocalDynamics\Revisionable\ServiceProvider;
use LocalDynamics\Revisionable\Tests\Models\LimitedHistory\User as UserWithLimitedHistory;
use LocalDynamics\Revisionable\Tests\Models\User;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations(['--database' => 'testing']);

        $this->loadMigrationsFrom([
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../src/migrations'),
        ]);

        $this->loadMigrationsFrom([
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../tests/migrations'),
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge($attributes, [
            'name' => 'Peter Parker',
            'email' => 'peter.parker@revisionable.test',
            'password' => \Hash::make('secret'),
        ]));
    }

    protected function createUserWithLimitedHistory(array $attributes = []): UserWithLimitedHistory
    {
        return UserWithLimitedHistory::create(array_merge($attributes, [
            'name' => 'Peter Parker',
            'email' => 'peter.parker@revisionable.test',
            'password' => \Hash::make('secret'),
        ]));
    }
}
