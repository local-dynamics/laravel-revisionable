<?php

namespace LocalDynamics\Revisionable\Tests\Models\LimitedHistory;

use Illuminate\Database\Eloquent\Model;
use LocalDynamics\Revisionable\Concerns\IsRevisionable;

/**
 * Add a revisionable model for testing purposes
 * I've chosen User, purely because the migration will already exist
 */
class User extends Model
{
    use IsRevisionable;

    protected $casts = [
        'settings' => 'array',
    ];

    protected $guarded = [];

    protected $historyLimit = 200;

    protected $revisionCleanup = true;

    public function getHistoryLimit()
    {
        return $this->historyLimit;
    }
}
