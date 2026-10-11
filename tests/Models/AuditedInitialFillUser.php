<?php

namespace LocalDynamics\Revisionable\Tests\Models;

class AuditedInitialFillUser extends InitialFillUser
{
    public function getSystemUserId(): ?int
    {
        return 42;
    }
}
