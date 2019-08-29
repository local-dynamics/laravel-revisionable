<?php

namespace LocalDynamics\Revisionable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use LocalDynamics\Revisionable\Concerns\IsRevisionable;

/**
 * Add a revisionable model for testing purposes
 * I've chosen User, purely because the migration will already exist
 *
 * Class User
 * @package LocalDynamics\Revisionable\Tests\Models
 */
class User extends Model
{
    use IsRevisionable;

    protected $casts = [
        'settings' => 'array',
    ];

    protected $guarded = [];
}
