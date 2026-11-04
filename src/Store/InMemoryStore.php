<?php

declare(strict_types=1);

namespace AgentFlo\Store;

use AgentFlo\FloScope;

/**
 * A reference store, for tests and for reading what the contract means. A flow
 * owned by null is shared by every tenant; a run always belongs to one.
 */
class InMemoryStore implements FlowStore, RunStore
{
    /** @var array<string,array{owner:?string,schema:array,version:int}> */
    private array $flows = [];

    /** @var array<string,RunRecord&object> */
    private array $runs = [];

    /** @var array<string,?string> */
    private array $runOwners = [];

    /** @param array<string,mixed> $schema */
    public function seedFlow(?string $tenant, array $schema): string
    {
        $id = 'flow_'.(count($this->flows) + 1);
        $this->flows[$id] = ['owner' => $tenant, 'schema' => $schema, 'version' => 1];

        return $id;
    }

    /** @param array<string,mixed> $outputs */
    public function seedRun(string $tenant, string $flowId, string $status, ?string $error = null, array $outputs = []): string
    {
        $id = 'run_'.(count($this->runs) + 1);
        $this->runs[$id] = new RunRecord($id, $flowId, $status, $error, $outputs, '2026-11-04T00:00:00Z');
        $this->runOwners[$id] = $tenant;

        return $id;
    }

    protected function visible(?string $owner, FloScope $scope, bool $shared): bool
    {
        return $owner === $scope->tenantKey() || ($shared && $owner === null);
    }

    public function list(FloScope $scope, ?string $cursor = null, int $limit = 50): array
    {
        $items = [];
        foreach ($this->flows as $id => $flow) {
            if ($this->visible($flow['owner'], $scope, true)) {
                $items[] = $this->record($id);
            }
        }
        $offset = (int) ($cursor ?? 0);
        $page = array_slice($items, $offset, $limit);

        return ['items' => $page, 'next' => $offset + $limit < count($items) ? (string) ($offset + $limit) : null];
    }

    public function find(FloScope $scope, string $id): ?FlowRecord
    {
        $flow = $this->flows[$id] ?? null;

        return $flow !== null && $this->visible($flow['owner'], $scope, true) ? $this->record($id) : null;
    }

    public function create(FloScope $scope, array $schema): FlowRecord
    {
        return $this->record($this->seedFlow($scope->tenantKey(), $schema));
    }

    public function save(FloScope $scope, string $id, array $schema, string $basedOn): FlowRecord
    {
        $current = $this->find($scope, $id);
        if ($current === null || ! hash_equals($current->revision, $basedOn)) {
            throw new StaleRevision;
        }
        $this->flows[$id]['schema'] = $schema;
        $this->flows[$id]['version']++;

        return $this->record($id);
    }

    public function capabilities(): array
    {
        return ['create'];
    }

    public function runs(FloScope $scope, ?string $flowId = null, int $limit = 50): array
    {
        $out = [];
        foreach ($this->runs as $id => $run) {
            if ($this->visible($this->runOwners[$id], $scope, false) && ($flowId === null || $run->flowId === $flowId)) {
                $out[] = $run;
            }
        }

        return array_slice($out, 0, $limit);
    }

    public function run(FloScope $scope, string $id): ?RunRecord
    {
        return isset($this->runs[$id]) && $this->visible($this->runOwners[$id], $scope, false) ? $this->runs[$id] : null;
    }

    private function record(string $id): FlowRecord
    {
        $flow = $this->flows[$id];
        $name = $flow['schema']['metadata']['name'] ?? null;

        return new FlowRecord($id, Revision::of($flow['schema'], $flow['version']), $flow['schema'], is_string($name) ? $name : null);
    }
}
