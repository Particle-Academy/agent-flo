<?php

declare(strict_types=1);

namespace AgentFlo\Store;

use AgentFlo\FloScope;

/**
 * The host's flows. Every method takes the scope and is held to its tenant: a
 * flow the scope may not see is null, exactly as a missing one is. A write names
 * the revision it was based on and refuses with {@see StaleRevision} if that is
 * not the current one. Run `AgentFlo\Testing\StoreConformance` against yours.
 */
interface FlowStore
{
    /** @return array{items:list<FlowRecord>,next:?string} */
    public function list(FloScope $scope, ?string $cursor = null, int $limit = 50): array;

    public function find(FloScope $scope, string $id): ?FlowRecord;

    /** @param array<string,mixed> $schema */
    public function create(FloScope $scope, array $schema): FlowRecord;

    /**
     * @param array<string,mixed> $schema
     *
     * @throws StaleRevision when `$basedOn` is not the current revision, or the flow is not visible
     */
    public function save(FloScope $scope, string $id, array $schema, string $basedOn): FlowRecord;

    /** @return list<string> optional verbs this store supports: `create`, `publish`, `disable`, `versions` */
    public function capabilities(): array;
}
