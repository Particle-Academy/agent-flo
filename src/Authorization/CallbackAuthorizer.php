<?php

declare(strict_types=1);

namespace AgentFlo\Authorization;

use AgentFlo\FloScope;
use Closure;

final class CallbackAuthorizer implements Authorizer
{
    /** @param Closure(FloScope, string, ?string): bool $callback */
    public function __construct(private readonly Closure $callback) {}

    public function allows(FloScope $scope, string $ability, ?string $target = null): bool
    {
        return ($this->callback)($scope, $ability, $target) === true;
    }
}
