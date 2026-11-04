<?php

declare(strict_types=1);

namespace AgentFlo\Authorization;

use AgentFlo\FloScope;

/** The default: nothing is allowed until the host says what is. */
final class DenyAll implements Authorizer
{
    public function allows(FloScope $scope, string $ability, ?string $target = null): bool
    {
        return false;
    }
}
