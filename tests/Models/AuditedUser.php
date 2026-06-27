<?php

namespace LocalDynamics\Revisionable\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use LocalDynamics\Revisionable\Concerns\IsRevisionable;

class AuditedUser extends Model
{
    use IsRevisionable;

    protected $table = 'users';

    protected $guarded = [];

    public function getSystemUserId(): ?int
    {
        return 42;
    }
}
