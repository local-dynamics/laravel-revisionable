<?php

namespace LocalDynamics\Revisionable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use LocalDynamics\Revisionable\Concerns\IsRevisionable;

class InitialFillUser extends Model
{
    use IsRevisionable;

    protected $table = 'users';

    protected $guarded = [];

    protected $revisionInitialFillWindow = 300;
}
