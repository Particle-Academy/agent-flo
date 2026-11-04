<?php

declare(strict_types=1);

namespace AgentFlo\Store;

use LogicException;

final class StoreNotConfigured extends LogicException
{
    public static function make(): self
    {
        return new self(
            'agent-flo has no FlowStore/RunStore bound. fancy-flow-php\'s tables have no tenant column, so the '
            .'default adapter needs an explicit choice: bind FancyFlowPhpStore::singleTenant() or '
            .'FancyFlowPhpStore::scopedBy(fn ($query, FloScope $scope, string $what) => ...), or your own store.'
        );
    }
}
