<?php

declare(strict_types=1);

namespace AgentFlo\Store;

use AgentFlo\FloScope;
use Closure;
use FancyFlow\Laravel\Models\Workflow;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The default adapter over fancy-flow-php's `Workflow` and `WorkflowRun`.
 *
 * Those tables have NO tenant column, so this refuses to exist without an explicit
 * choice: {@see singleTenant()} for an app with one tenant, or {@see scopedBy()} with
 * the host's own rule, applied separately to flows and to runs.
 */
final class FancyFlowPhpStore implements FlowStore, RunStore
{
    /**
     * @param (Closure(Builder, FloScope, string): void)|null $scope
     * @param (Closure(Model, FloScope): void)|null $stamp
     */
    private function __construct(private readonly ?Closure $scope, private readonly ?Closure $stamp, private readonly bool $single) {}

    public static function singleTenant(): self
    {
        return new self(null, null, true);
    }

    /**
     * @param Closure(Builder $query, FloScope $scope, string $what): void $scope `$what` is `flows` or `runs`
     * @param (Closure(Model $workflow, FloScope $scope): void)|null $stamp sets the tenant on a new flow; without it the store cannot create
     */
    public static function scopedBy(Closure $scope, ?Closure $stamp = null): self
    {
        return new self($scope, $stamp, false);
    }

    public function list(FloScope $scope, ?string $cursor = null, int $limit = 50): array
    {
        $rows = $this->flows($scope)->orderBy('id')->when($cursor !== null, fn ($q) => $q->where('id', '>', (int) $cursor))->limit($limit + 1)->get();
        $items = $rows->take($limit)->map(fn (Workflow $w) => $this->record($w))->values()->all();

        return ['items' => $items, 'next' => $rows->count() > $limit ? (string) $rows[$limit - 1]->getKey() : null];
    }

    public function find(FloScope $scope, string $id): ?FlowRecord
    {
        $row = ctype_digit($id) ? $this->flows($scope)->whereKey((int) $id)->first() : null;

        return $row === null ? null : $this->record($row);
    }

    public function create(FloScope $scope, array $schema): FlowRecord
    {
        if (! $this->single && $this->stamp === null) {
            throw new StoreNotConfigured('This FancyFlowPhpStore was built without a stamp, so it cannot create a flow in a tenant.');
        }
        $workflow = new Workflow;
        $workflow->forceFill(['name' => $schema['metadata']['name'] ?? null, 'schema' => $schema, 'version' => 1]);
        if ($this->stamp !== null) {
            ($this->stamp)($workflow, $scope);
        }
        $workflow->save();

        return $this->record($workflow);
    }

    public function save(FloScope $scope, string $id, array $schema, string $basedOn): FlowRecord
    {
        return Workflow::query()->getConnection()->transaction(function () use ($scope, $id, $schema, $basedOn): FlowRecord {
            $row = ctype_digit($id) ? $this->flows($scope)->whereKey((int) $id)->lockForUpdate()->first() : null;
            if ($row === null || ! hash_equals($this->record($row)->revision, $basedOn)) {
                throw new StaleRevision;
            }
            $row->forceFill(['schema' => $schema, 'version' => (int) $row->version + 1, 'name' => $schema['metadata']['name'] ?? $row->name])->save();

            return $this->record($row);
        });
    }

    public function capabilities(): array
    {
        return $this->single || $this->stamp !== null ? ['create'] : [];
    }

    public function runs(FloScope $scope, ?string $flowId = null, int $limit = 50): array
    {
        return $this->runQuery($scope)
            ->when($flowId !== null, fn ($q) => $q->where('workflow_id', ctype_digit((string) $flowId) ? (int) $flowId : -1))
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (WorkflowRun $r) => $this->runRecord($r))->values()->all();
    }

    public function run(FloScope $scope, string $id): ?RunRecord
    {
        $row = $this->runQuery($scope)->where('run_key', $id)->first();

        return $row === null ? null : $this->runRecord($row);
    }

    private function flows(FloScope $scope): Builder
    {
        $query = Workflow::query();
        if ($this->scope !== null) {
            ($this->scope)($query, $scope, 'flows');
        }

        return $query;
    }

    private function runQuery(FloScope $scope): Builder
    {
        $query = WorkflowRun::query();
        if ($this->scope !== null) {
            ($this->scope)($query, $scope, 'runs');
        }

        return $query;
    }

    private function record(Workflow $row): FlowRecord
    {
        $schema = is_array($row->schema) ? $row->schema : [];

        return new FlowRecord((string) $row->getKey(), Revision::of($schema, (int) $row->version), $schema, $row->name === null ? null : (string) $row->name);
    }

    private function runRecord(WorkflowRun $row): RunRecord
    {
        return new RunRecord(
            (string) $row->run_key,
            $row->workflow_id === null ? null : (string) $row->workflow_id,
            (string) $row->status,
            $row->error === null ? null : (string) $row->error,
            is_array($row->outputs) ? $row->outputs : [],
            $row->created_at?->toIso8601String(),
        );
    }
}
