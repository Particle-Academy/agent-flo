<?php

declare(strict_types=1);

namespace AgentFlo\Store;

use AgentFlo\FloScope;

/**
 * The host's runs, scoped on their own. Being able to see a flow says nothing
 * about its runs: a global flow admits every tenant, so authorising the flow is
 * not enough.
 */
interface RunStore
{
    /** @return list<RunRecord> */
    public function runs(FloScope $scope, ?string $flowId = null, int $limit = 50): array;

    public function run(FloScope $scope, string $id): ?RunRecord;
}
