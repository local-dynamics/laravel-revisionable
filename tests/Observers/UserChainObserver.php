<?php

namespace LocalDynamics\Revisionable\Tests\Observers;

use LocalDynamics\Revisionable\Tests\Models\User;

/**
 * Triggers a two-level nested save chain from within the updated event:
 * Paul -> Mary -> Jane.
 */
class UserChainObserver
{
    public function updated(User $user): void
    {
        if ($user->name === 'Paul') {
            $user->name = 'Mary';
            $user->save();
        } elseif ($user->name === 'Mary') {
            $user->name = 'Jane';
            $user->save();
        }
    }
}
