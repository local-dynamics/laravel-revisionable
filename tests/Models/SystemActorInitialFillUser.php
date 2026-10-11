<?php

namespace LocalDynamics\Revisionable\Tests\Models;

class SystemActorInitialFillUser extends AuditedInitialFillUser
{
    protected function revisionInitialFillAppliesToActor(): bool
    {
        return true;
    }
}
