<?php

declare(strict_types=1);

use AgentFlo\FloScope;
use AgentFlo\Store\FancyFlowPhpStore;
use AgentFlo\Store\InMemoryStore;
use AgentFlo\Store\StoreNotConfigured;
use AgentFlo\Testing\StoreConformance;
use AgentFlo\Testing\StoreFixture;
use FancyFlow\Laravel\Models\Workflow;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The fixture a host writes for its own tables. These two are the package's. */
function memoryFixture(): StoreFixture
{
    $store = new InMemoryStore;

    return new class($store) implements StoreFixture
    {
        public function __construct(private readonly InMemoryStore $store) {}

        public function flows(): \AgentFlo\Store\FlowStore { return $this->store; }

        public function runs(): \AgentFlo\Store\RunStore { return $this->store; }

        public function seedFlow(?FloScope $owner, array $schema): string { return $this->store->seedFlow($owner?->tenantKey(), $schema); }

        public function seedRun(FloScope $owner, string $flowId): string { return $this->store->seedRun($owner->tenantKey(), $flowId, 'failed', error: 'boom'); }
    };
}

/** fancy-flow-php's tables have no tenant column, so the host adds one. */
function eloquentFixture(): StoreFixture
{
    foreach (['fancy_flow_workflows', 'fancy_flow_workflow_runs'] as $table) {
        Schema::table($table, fn (Blueprint $t) => $t->string('tenant')->nullable());
    }
    $store = FancyFlowPhpStore::scopedBy(function (Builder $query, FloScope $scope, string $what): void {
        $what === 'flows'
            ? $query->where(fn ($q) => $q->whereNull('tenant')->orWhere('tenant', $scope->tenantKey()))
            : $query->where('tenant', $scope->tenantKey());
    });

    return new class($store) implements StoreFixture
    {
        public function __construct(private readonly FancyFlowPhpStore $store) {}

        public function flows(): \AgentFlo\Store\FlowStore { return $this->store; }

        public function runs(): \AgentFlo\Store\RunStore { return $this->store; }

        public function seedFlow(?FloScope $owner, array $schema): string
        {
            return (string) Workflow::query()->forceCreate(['name' => $schema['metadata']['name'] ?? null, 'schema' => $schema, 'version' => 1, 'tenant' => $owner?->tenantKey()])->getKey();
        }

        public function seedRun(FloScope $owner, string $flowId): string
        {
            $run = new WorkflowRun;
            $run->forceFill(['run_key' => 'run_'.bin2hex(random_bytes(6)), 'workflow_id' => (int) $flowId, 'status' => 'failed', 'schema' => [], 'error' => 'boom', 'tenant' => $owner->tenantKey()])->save();

            return (string) $run->run_key;
        }
    };
}

it('passes the store conformance kit on the in-memory store', function (): void {
    floApp();

    expect(StoreConformance::run(memoryFixture()))->toBe([]);
});

it('passes the kit on the fancy-flow-php adapter, scoped by the host', function (): void {
    floApp();

    expect(StoreConformance::run(eloquentFixture()))->toBe([]);
});

it('refuses to build the default adapter without an explicit tenancy choice', function (): void {
    expect((new ReflectionClass(FancyFlowPhpStore::class))->getConstructor()?->isPublic())->toBeFalse();

    $app = floApp();

    expect(fn () => $app->make(\AgentFlo\Store\FlowStore::class))->toThrow(StoreNotConfigured::class);
});

it('positive control: the kit catches a store that leaks across tenants', function (): void {
    floApp();
    $leaky = new class extends InMemoryStore
    {
        protected function visible(?string $owner, FloScope $scope, bool $shared): bool { return true; }
    };
    $fixture = new class($leaky) implements StoreFixture
    {
        public function __construct(private readonly InMemoryStore $store) {}

        public function flows(): \AgentFlo\Store\FlowStore { return $this->store; }

        public function runs(): \AgentFlo\Store\RunStore { return $this->store; }

        public function seedFlow(?FloScope $owner, array $schema): string { return $this->store->seedFlow($owner?->tenantKey(), $schema); }

        public function seedRun(FloScope $owner, string $flowId): string { return $this->store->seedRun($owner->tenantKey(), $flowId, 'failed'); }
    };

    expect(StoreConformance::run($fixture))->toContain('a run in another tenant is invisible, even of a flow both can see')
        ->toContain('a flow in another tenant is invisible');
});
