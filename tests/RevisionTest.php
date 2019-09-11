<?php

namespace LocalDynamics\Revisionable\Tests;

use Hash;
use LocalDynamics\Revisionable\Models\Revision;
use LocalDynamics\Revisionable\Tests\Models\User;

class RevisionTest extends TestCase
{
    /** @test */
    public function user_table_is_working()
    {
        $this->createUser();

        $user = User::findOrFail(1);
        $this->assertEquals('peter.parker@revisionable.test', $user->email);
        $this->assertTrue(Hash::check('secret', $user->password));
    }

    /** @test */
    public function user_setting_is_an_array()
    {
        $this->createUser([
            'settings' => [
                'settingA' => true,
                'settingB' => 200,
                'settingC' => 'ABCabc?',
            ],
        ]);

        $user = User::findOrFail(1);

        $this->assertIsArray($user->settings);
        $this->assertArrayHasKey('settingA', $user->settings);
        $this->assertArrayHasKey('settingB', $user->settings);
        $this->assertArrayHasKey('settingC', $user->settings);
    }

    /** @test */
    public function revisions_get_stored()
    {
        $user = $this->createUser();

        $user->update(['name' => 'Spiderman']);

        $user->update(['name' => 'Spidy']);

        $this->assertCount(2, $user->revisionHistory);
    }

    /** @test */
    public function revisions_dont_get_stored_if_config_disabled()
    {
        $user = $this->createUser();

        config(['revisionable.enabled' => false]);

        $user->update(['name' => 'Spiderman']);

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

        $user->update([
            'settings' => [
                'settingA' => false,
                'settingB' => 200,
                'settingC' => 'ABCabc?',
                'settingD' => ['A', 'B', 'C'],
            ],
        ]);

        $user->fresh();

        $user->update([
            'settings' => ['settingD' => 'test'],
        ]);

        $this->assertCount(2, $user->revisionHistory);
    }

    {
    }

    {
    }
}
