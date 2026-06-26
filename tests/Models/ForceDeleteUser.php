<?php

namespace LocalDynamics\Revisionable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use LocalDynamics\Revisionable\Concerns\IsRevisionable;

class ForceDeleteUser extends Model
{
    use IsRevisionable;

    protected $table = 'users';

    protected $guarded = [];

    protected bool $revisionForceDeleteEnabled = true;
}
