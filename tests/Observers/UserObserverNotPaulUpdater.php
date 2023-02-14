<?php

namespace LocalDynamics\Revisionable\Tests\Observers;

use LocalDynamics\Revisionable\Tests\Models\User;

class UserObserverNotPaulUpdater
{
    public function updated(User $user)
    {
        if ($user->name == 'Paul') {
            $user->name = 'not Peter';
            $user->save();
        }
    }
}
