<?php

namespace LocalDynamics\Revisionable\Tests;

use Hash;
use LocalDynamics\Revisionable\ServiceProvider;
use LocalDynamics\Revisionable\Tests\Models\User;
use Orchestra\Testbench\TestCase;

class RevisionTest extends TestCase
{
    protected function setUp() : void
    {
        parent::setUp();

        $this->loadLaravelMigrations(['--database' => 'testing']);

        $this->loadMigrationsFrom([
            '--database' => 'testing',
            '--path'     => realpath(__DIR__ . '/../src/migrations'),
        ]);

        $this->loadMigrationsFrom([
            '--database' => 'testing',
            '--path'     => realpath(__DIR__ . '/../tests/migrations'),
        ]);
    }

    /** @test */
    public function user_table_is_working()
    {
        User::create([
            'name'     => 'James Judd',
            'email'    => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
        ]);

        $users = User::findOrFail(1);
        $this->assertEquals('james.judd@revisionable.test', $users->email);
        $this->assertTrue(Hash::check('456', $users->password));
    }

    /** @test */
    public function user_setting_is_an_array()
    {
        User::create([
            'name'     => 'James Judd',
            'email'    => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
            'settings' => [
                'settingA' => true,
                'settingB' => 200,
                'settingC' => 'ABCabc?',
            ],
        ]);

        $users = User::findOrFail(1);
        $this->assertEquals('james.judd@revisionable.test', $users->email);
        $this->assertTrue(Hash::check('456', $users->password));
        $this->assertIsArray($users->settings);
        $this->assertArrayHasKey('settingA', $users->settings);
        $this->assertArrayHasKey('settingB', $users->settings);
        $this->assertArrayHasKey('settingC', $users->settings);
    }

    /** @test */
    public function revisions_get_stored()
    {
        $user = User::create([
            'name'     => 'James Judd',
            'email'    => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
        ]);

        // change to my nickname
        $user->update([
            'name' => 'Judd',
        ]);

        // change to my forename
        $user->update([
            'name' => 'James',
        ]);

        // we should have two revisions to my name
        $this->assertCount(2, $user->revisionHistory);
    }

    /** @test */
    public function revisions_dont_get_stored_if_config_disabled()
    {
        $user = User::create([
            'name'     => 'James Judd',
            'email'    => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
        ]);

        config(['revisionable.enabled' => false]);

        // change to my nickname
        $user->update([
            'name' => 'Judd',
        ]);

        // change to my forename
        $user->update([
            'name' => 'James',
        ]);

        // we should have two revisions to my name
        $this->assertCount(0, $user->revisionHistory);
    }

    /** @test */
    public function revisions_of_array_fields_get_stored()
    {
        $user = User::create([
            'name'     => 'James Judd',
            'email'    => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
            'settings' => [
                'settingA' => true,
                'settingB' => 200,
                'settingC' => 'ABCabc?',
            ],
        ]);

        // change to my nickname
        $user->update([
            'settings' => [
                'settingA' => false,
                'settingB' => 200,
                'settingC' => 'ABCabc?',
                'settingD' => ['A', 'B', 'C'],
            ],
        ]);
        $user->fresh();

        // change to my forename
        $user->update([
            'settings' => ['settingD' => 'test'],
        ]);

        // we should have two revisions to my name
        $this->assertCount(2, $user->revisionHistory);
    }

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }
}
