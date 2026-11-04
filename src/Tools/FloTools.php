<?php

declare(strict_types=1);

namespace AgentFlo\Tools;

use AgentFlo\AgentFlo;
use AgentFlo\Delegations\Delegation;
use AgentFlo\Delegations\DelegationDraftStore;
use AgentFlo\Refused;
use AgentFlo\Store\StaleRevision;
use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\Mcp\Server\FlowBuilderServer;
use FancyFlow\Mcp\Tools;
use FancyFlow\Registry\KindId;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Name;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;
use ReflectionClass;
use Throwable;

/**
 * Everything one delegation's child may call.
 *
 * Two families. Flo's own `flo_*` tools read the host's flows and runs through
 * its stores and submit changes as proposals. The authoring tools ARE
 * fancy-flow-mcp's own tool classes, called IN PROCESS: each is constructed with
 * the shared `FlowAuthoring` and a draft store held to this delegation, and its
 * `handle()` is invoked directly with a `Laravel\Mcp\Request`. No MCP server, no
 * transport, no second copy of the authoring logic. `run_workflow` is left out:
 * it runs fake executors, and a smoke run is a separate decision.
 *
 * Scope, tenant and actor come from the delegation row, re-read on every call.
 */
final class FloTools
{
    public const OWN = ['flo_list', 'flo_get', 'flo_runs', 'flo_run', 'flo_propose'];

    /** fancy-flow-mcp's tools Flo offers, by class. */
    public const MCP = [
        Tools\ListNodeKindsTool::class, Tools\DescribeNodeKindTool::class,
        Tools\CreateWorkflowTool::class, Tools\ImportWorkflowTool::class, Tools\GetWorkflowTool::class,
        Tools\ListWorkflowsTool::class, Tools\DeleteWorkflowTool::class,
        Tools\AddNodeTool::class, Tools\ConfigureNodeTool::class, Tools\ConnectNodesTool::class,
        Tools\RemoveNodeTool::class, Tools\RemoveEdgeTool::class,
        Tools\ValidateWorkflowTool::class, Tools\ExportWorkflowTool::class,
    ];

    private const ARGS = [
        'flo_list' => ['cursor' => false],
        'flo_get' => ['flow_id' => true],
        'flo_runs' => ['flow_id' => false],
        'flo_run' => ['run_id' => true],
        'flo_propose' => ['workflow_id' => true, 'flow_id' => false, 'based_on' => false, 'rationale' => false],
    ];

    private const DESCRIPTIONS = [
        'flo_list' => 'List the {{flows}} you may see, with each one\'s id and revision. Names are under `untrusted`. Pass `cursor` from `next` for the following page.',
        'flo_get' => 'Read one {{flow}}: its {{steps}} (id and kind) and its revision, with the full document under `untrusted`. Pass `flow_id`.',
        'flo_runs' => 'List recent {{runs}} and their status, optionally for one {{flow}} (`flow_id`).',
        'flo_run' => 'Read one {{run}} (`run_id`): status, and its outputs and error text under `untrusted`. After this, anything you do is a proposal.',
        'flo_propose' => 'Submit a draft you built with the authoring tools (`workflow_id`) as a new {{flow}}, or as a change to `flow_id` based on the revision you read (`based_on`). Usually this stages a proposal a person approves: `staged: true` means nothing changed yet. Give a short `rationale`.',
    ];

    /** @var array<string,\Laravel\Mcp\Server\Tool>|null */
    private ?array $mcp = null;

    public function __construct(private readonly AgentFlo $flo, private readonly string $delegation) {}

    /** @return list<string> */
    public static function mcpNames(): array
    {
        return array_map(static function (string $class): string {
            $attribute = (new ReflectionClass($class))->getAttributes(Name::class)[0] ?? null;

            return (string) ($attribute?->getArguments()[0] ?? '');
        }, self::MCP);
    }

    /** @return list<string> */
    public static function allNames(): array
    {
        return [...self::OWN, ...self::mcpNames()];
    }

    /** @return list<string> */
    public function names(): array
    {
        return self::allNames();
    }

    /** @return array<string,string> Flo's own tool descriptions, in the host's words */
    public function ownDescriptions(): array
    {
        return array_map(fn (string $d): string => $this->flo->vocabulary()->render($d), self::DESCRIPTIONS);
    }

    /** @return list<Tool> */
    public function prismTools(): array
    {
        $tools = [];
        foreach ($this->ownDescriptions() as $name => $description) {
            $tool = (new Tool)->as($name)->for($description);
            foreach (self::ARGS[$name] as $arg => $required) {
                $tool->withStringParameter($arg, str_replace('_', ' ', $arg).'.', $required);
            }
            $tools[] = $tool->using(fn (mixed ...$args): string => $this->call($name, $args));
        }
        foreach ($this->mcpTools() as $name => $mcp) {
            $schema = $mcp->toArray()['inputSchema'] ?? [];
            $required = (array) ($schema['required'] ?? []);
            $tool = (new Tool)->as($name)->for((string) $mcp->description());
            foreach ((array) ($schema['properties'] ?? []) as $prop => $spec) {
                $tool->withParameter(new RawSchema((string) $prop, (array) $spec), in_array($prop, $required, true));
            }
            $tools[] = $tool->using(fn (mixed ...$args): string => $this->call($name, $args));
        }

        return $tools;
    }

    /** @param array<string,mixed> $args */
    public function call(string $name, array $args = []): string
    {
        $delegation = $this->flo->delegations()->find($this->delegation) ?? throw new Refused('no_delegation');
        $this->flo->delegations()->recordTool($this->delegation, $name);

        try {
            if (in_array($name, self::OWN, true)) {
                $this->assertArgs($name, $args);
                $result = match ($name) {
                    'flo_list' => $this->list($delegation, $args),
                    'flo_get' => $this->get($delegation, (string) $args['flow_id']),
                    'flo_runs' => $this->runs($delegation, $args),
                    'flo_run' => $this->run($delegation, (string) $args['run_id']),
                    'flo_propose' => $this->propose($delegation, $args),
                };
            } elseif (isset($this->mcpTools()[$name])) {
                return $this->callMcp($name, $args);
            } else {
                throw new Refused('unknown_tool');
            }
        } catch (Refused $refused) {
            $result = ['isError' => true, 'error' => ['code' => $refused->reason]];
        }

        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $args */
    private function list(Delegation $d, array $args): array
    {
        if (! $this->flo->authorizer()->allows($d->scope, 'view')) {
            return Untrusted::frame(['items' => [], 'next' => null]);
        }
        $page = $this->flo->flows()->list($d->scope, isset($args['cursor']) ? (string) $args['cursor'] : null);
        $items = [];
        $names = [];
        foreach ($page['items'] as $flow) {
            if ($this->flo->authorizer()->allows($d->scope, 'view', $flow->id)) {
                $items[] = ['id' => $flow->id, 'revision' => $flow->revision, 'untrusted' => ['name' => $flow->name]];
            }
        }

        return Untrusted::frame(['items' => $items, 'next' => $page['next']]);
    }

    private function get(Delegation $d, string $id): array
    {
        $flow = $this->visibleFlow($d, $id);
        $steps = [];
        foreach ($flow->schema['graph']['nodes'] ?? [] as $node) {
            if (is_array($node) && is_string($node['id'] ?? null)) {
                $steps[] = ['id' => $node['id'], 'kind' => KindId::bare((string) ($node['kind'] ?? ''))];
            }
        }

        return Untrusted::frame(
            ['id' => $flow->id, 'revision' => $flow->revision, 'steps' => $steps],
            ['name' => $flow->name, 'schema' => $flow->schema],
        );
    }

    /** @param array<string,mixed> $args */
    private function runs(Delegation $d, array $args): array
    {
        if (! $this->flo->authorizer()->allows($d->scope, 'view')) {
            return Untrusted::frame(['items' => []]);
        }
        $flowId = isset($args['flow_id']) ? (string) $args['flow_id'] : null;
        $items = [];
        foreach ($this->flo->runs()->runs($d->scope, $flowId) as $run) {
            if ($this->flo->authorizer()->allows($d->scope, 'view', $run->id)) {
                $items[] = ['id' => $run->id, 'flowId' => $run->flowId, 'status' => $run->status, 'startedAt' => $run->startedAt];
            }
        }

        return Untrusted::frame(['items' => $items]);
    }

    private function run(Delegation $d, string $id): array
    {
        $run = $this->flo->runs()->run($d->scope, $id);
        if ($run === null || ! $this->flo->authorizer()->allows($d->scope, 'view', $id)) {
            throw new Refused('not_found');
        }
        // Marked BEFORE the content is returned: from here on, change is a proposal.
        $this->flo->delegations()->taint($d->id);

        return Untrusted::frame(
            ['id' => $run->id, 'flowId' => $run->flowId, 'status' => $run->status, 'startedAt' => $run->startedAt],
            ['error' => $run->error, 'outputs' => $run->outputs],
        );
    }

    /** @param array<string,mixed> $args */
    private function propose(Delegation $d, array $args): array
    {
        if ($d->scope->isSystem() && ! $this->flo->config('system_scope_may_change', false)) {
            throw new Refused('read_only_scope');
        }
        $target = isset($args['flow_id']) ? (string) $args['flow_id'] : null;
        if (! $this->flo->authorizer()->allows($d->scope, 'change', $target)) {
            throw new Refused('not_found');
        }
        $draft = $this->draftStore($d)->get((string) $args['workflow_id']) ?? throw new Refused('draft_not_found');

        // The one validation chain: fancy-flow-php's, through FlowAuthoring.
        $validation = $this->authoring()->validate($draft);
        if (! $validation['ok']) {
            return Untrusted::frame(['isError' => true, 'error' => ['code' => 'invalid_graph']], ['errors' => $validation['errors']]);
        }

        $basedOn = isset($args['based_on']) ? (string) $args['based_on'] : null;
        if ($target !== null) {
            $current = $this->visibleFlow($d, $target);
            if ($basedOn === null || ! hash_equals($current->revision, $basedOn)) {
                throw new Refused('stale_revision');
            }
        }

        $schema = $this->authoring()->schemaArray($draft);
        $why = $this->flo->approvalsNeeded($d);
        if ($why === []) {
            try {
                $record = $target === null
                    ? $this->flo->flows()->create($d->scope, $schema)
                    : $this->flo->flows()->save($d->scope, $target, $schema, (string) $basedOn);
            } catch (StaleRevision) {
                throw new Refused('stale_revision');
            }

            return ['applied' => true, 'flowId' => $record->id, 'revision' => $record->revision];
        }

        $rationale = isset($args['rationale']) ? (string) $args['rationale'] : null;
        $proposal = $this->flo->proposals()->create($d, $target, $basedOn, $schema, $rationale);

        return ['staged' => true, 'proposalId' => $proposal->id, 'why' => $why, 'diff' => $proposal->diff];
    }

    private function visibleFlow(Delegation $d, string $id): \AgentFlo\Store\FlowRecord
    {
        $flow = $this->flo->flows()->find($d->scope, $id);
        if ($flow === null || ! $this->flo->authorizer()->allows($d->scope, 'view', $id)) {
            throw new Refused('not_found');
        }

        return $flow;
    }

    /** @param array<string,mixed> $args */
    private function assertArgs(string $name, array $args): void
    {
        foreach (array_keys($args) as $key) {
            if (! array_key_exists((string) $key, self::ARGS[$name])) {
                throw new Refused('invalid_arguments');
            }
        }
        foreach (self::ARGS[$name] as $arg => $required) {
            $value = $args[$arg] ?? null;
            if (($required && ! is_string($value)) || ($value !== null && (! is_string($value) || strlen($value) > 4096))) {
                throw new Refused('invalid_arguments');
            }
        }
    }

    /** @param array<string,mixed> $args */
    private function callMcp(string $name, array $args): string
    {
        try {
            $response = $this->mcpTools()[$name]->handle(new Request($args));
        } catch (Throwable) {
            // Only a code travels; the message stays in the process.
            return (string) json_encode(['isError' => true, 'error' => ['code' => 'authoring_failed']]);
        }
        $text = (string) ($response->content()->toArray()['text'] ?? '');

        return $response->isError()
            ? (string) json_encode(['isError' => true, 'error' => ['code' => 'authoring_refused', 'message' => $text]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $text;
    }

    /** @return array<string,\Laravel\Mcp\Server\Tool> */
    private function mcpTools(): array
    {
        if ($this->mcp !== null) {
            return $this->mcp;
        }
        $delegation = $this->flo->delegations()->find($this->delegation) ?? throw new Refused('no_delegation');
        $store = $this->draftStore($delegation);
        $this->mcp = [];
        foreach (self::MCP as $class) {
            $tool = new $class($this->authoring(), $store);
            $this->mcp[$tool->name()] = $tool;
        }

        return $this->mcp;
    }

    private function draftStore(Delegation $d): DelegationDraftStore
    {
        return new DelegationDraftStore($this->flo->delegations(), $d->id);
    }

    private function authoring(): FlowAuthoring
    {
        return $this->flo->authoring();
    }
}
