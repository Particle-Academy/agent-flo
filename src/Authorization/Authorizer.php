<?php

declare(strict_types=1);

namespace AgentFlo\Authorization;

use AgentFlo\FloScope;

/**
 * The host's answer to "may this scope do this". Abilities: `view`, `change`,
 * `approve` (and later `start`, `control`). `$target` is a flow or run id, or
 * null for a list or a new flow. Deny by default: {@see DenyAll}.
 */
interface Authorizer
{
    public function allows(FloScope $scope, string $ability, ?string $target = null): bool;
}
