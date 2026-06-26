<?php

namespace LocalDynamics\Revisionable\Tests;

use Hash;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LocalDynamics\Revisionable\Models\Revision;
use LocalDynamics\Revisionable\Tests\Models\AuditedUser;
use LocalDynamics\Revisionable\Tests\Models\ForceDeleteUser;
use LocalDynamics\Revisionable\Tests\Models\User;
use LocalDynamics\Revisionable\Tests\Observers\UserObserverNotPaulUpdater;
use PHPUnit\Framework\Attributes\Test;

class RevisionTest extends TestCase
{
    #[Test]
    public function user_table_is_working()
    {
        $this->createUser();

        $user = User::findOrFail(1);
        $this->assertEquals('peter.parker@revisionable.test', $user->email);
        $this->assertTrue(Hash::check('secret', $user->password));
    }

    #[Test]
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

    #[Test]
    public function the_system_user_id_hook_is_used_for_user_id()
    {
        $this->createUser();

        $user = AuditedUser::findOrFail(1);
        $user->update(['name' => 'Spiderman']);

        $this->assertSame(42, $user->revisionHistory->first()->user_id);
    }

    #[Test]
    public function class_revision_history_returns_changes_across_instances()
    {
        $peter = $this->createUser();
        $peter->update(['name' => 'Spiderman']);

        $james = User::create([
            'name' => 'James Judd',
            'email' => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
        ]);
        $james->update(['name' => 'Wolverine']);

        $history = User::classRevisionHistory();

        $this->assertCount(2, $history);
        // Newest first by default.
        $this->assertSame('Wolverine', $history->first()->new_value);
    }

    #[Test]
    public function revisions_get_stored()
    {
        $user = $this->createUser();

        $user->update(['name' => 'Spiderman']);

        $user->update(['name' => 'Spidy']);

        $this->assertCount(2, $user->revisionHistory);
    }

    #[Test]
    public function revisions_dont_get_stored_if_config_disabled()
    {
        $user = $this->createUser();

        config(['revisionable.enabled' => false]);

        $user->update(['name' => 'Spiderman']);

        $this->assertCount(0, $user->revisionHistory);
    }

    #[Test]
    public function revisions_of_array_fields_get_stored()
    {
        $user = User::create([
            'name' => 'James Judd',
            'email' => 'james.judd@revisionable.test',
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

    #[Test]
    public function morph_mapped_revisions_resolve_their_model()
    {
        Relation::morphMap(['user_alias' => User::class]);

        $user = $this->createUser();
        $user->update(['name' => 'Spidey']);

        $revision = $user->revisionHistory->first();

        $this->assertSame('user_alias', $revision->revisionable_type);
        $this->assertInstanceOf(User::class, $revision->historyOf());
    }

    #[Test]
    public function reordering_json_keys_does_not_create_a_revision()
    {
        $user = User::create([
            'name' => 'James Judd',
            'email' => 'james.judd@revisionable.test',
            'password' => Hash::make('456'),
            'settings' => ['alpha' => 1, 'b' => 2, 'gamma' => 3],
        ]);

        // Same data, different key order (as a database may store/return it).
        $user->update(['settings' => ['b' => 2, 'gamma' => 3, 'alpha' => 1]]);

        $this->assertFalse($user->revisionHistory->pluck('key')->contains('settings'));
    }

    #[Test]
    public function disable_revision_field_skips_those_fields()
    {
        $user = $this->createUser();

        $user->disableRevisionField(['name', 'email']);
        $user->update([
            'name' => 'Spiderman',
            'email' => 'spidey@revisionable.test',
            'password' => Hash::make('secret2'),
        ]);

        $keys = $user->revisionHistory->pluck('key');

        $this->assertFalse($keys->contains('name'));
        $this->assertFalse($keys->contains('email'));
        $this->assertTrue($keys->contains('password'));
    }

    #[Test]
    public function revision_of_carbon_dates()
    {
        $user = $this->createUser();
        $user->logged_in_at = now()->subDay();
        $user->save();

        $this->assertCount(1, $user->revisionHistory);
    }

    #[Test]
    public function revision_history_is_limited()
    {
        $user = $this->createUserWithLimitedHistory();
        $this->assertEquals(0, Revision::count());

        for ($i = 0; $i <= ($user->getHistoryLimit() + 10); $i++) {
            $user->update(['name' => 'Paul'.rand()]);
        }
        $this->assertEquals($user->getHistoryLimit(), Revision::count());
    }

    #[Test]
    public function force_delete_is_stored_as_a_revision()
    {
        $this->createUser();

        $user = ForceDeleteUser::findOrFail(1);
        $user->delete();

        $this->assertCount(1, $user->revisionHistory);
        $this->assertSame(User::CREATED_AT, $user->revisionHistory->first()->key);
        $this->assertNull($user->revisionHistory->first()->new_value);
    }

    #[Test]
    public function cleanup_keeps_the_newest_revisions()
    {
        $user = $this->createUserWithLimitedHistory();
        $limit = $user->getHistoryLimit();

        for ($i = 1; $i <= $limit + 5; $i++) {
            $user->update(['name' => 'v'.$i]);
        }

        $this->assertEquals($limit, Revision::count());
        // The most recent change must be retained ...
        $this->assertSame('v'.($limit + 5), Revision::orderByDesc('id')->first()->new_value);
        // ... and the five oldest changes must have been pruned.
        $this->assertSame('v6', Revision::orderBy('id')->first()->new_value);
    }

    #[Test]
    public function revision_are_stored_once_even_with_event_listeners()
    {
        Carbon::setTestNow('2020-01-01 00:00:00');
        $user = $this->createUser();
        $this->assertEquals(0, Revision::count());

        User::observe(UserObserverNotPaulUpdater::class);

        // Advance the clock so the updated_at timestamp actually changes.
        // The observer triggers a nested save() from within the updated
        // event, which used to leak an extra updated_at revision.
        Carbon::setTestNow('2020-01-01 00:00:05');
        $user->update(['name' => 'Paul', 'password' => Hash::make('secret2')]);
        Carbon::setTestNow();

        $this->assertEquals(3, Revision::count());
    }

    #[Test]
    public function several_changed_fields_are_written_in_a_single_insert()
    {
        $user = $this->createUser();

        DB::enableQueryLog();
        $user->update([
            'name' => 'Spiderman',
            'email' => 'spidey@revisionable.test',
        ]);
        $inserts = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'insert into "revisions"'));
        DB::disableQueryLog();

        $this->assertCount(2, $user->revisionHistory);
        $this->assertCount(1, $inserts);
    }

    #[Test]
    public function timestamps_are_not_revisioned()
    {
        Carbon::setTestNow('2020-01-01 00:00:00');
        $user = $this->createUser();

        Carbon::setTestNow('2020-01-01 00:00:05');
        $user->update(['name' => 'Spiderman']);
        Carbon::setTestNow();

        $keys = $user->revisionHistory->pluck('key');

        $this->assertTrue($keys->contains('name'));
        $this->assertFalse($keys->contains('updated_at'));
        $this->assertFalse($keys->contains('created_at'));
    }
}
