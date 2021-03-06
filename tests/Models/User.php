<?php

namespace LocalDynamics\Revisionable\Tests\Models;

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

    protected $dates = [
        'logged_in_at',
    ];

    protected $guarded = [];
}
