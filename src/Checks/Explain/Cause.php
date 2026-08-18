<?php

declare(strict_types=1);

namespace ElPandaPe\Warden\Checks\Explain;

enum Cause: string
{
    case GrantedDirectly = 'granted-directly';
    case GrantedViaRole = 'granted-via-role';
    case GrantedToEveryone = 'granted-to-everyone';
    case ForbiddenDirectly = 'forbidden-directly';
    case ForbiddenViaRole = 'forbidden-via-role';
    case ForbiddenToEveryone = 'forbidden-to-everyone';
    case NoMatchingGrant = 'no-matching-grant';
    case NotApplicable = 'not-applicable';
}
