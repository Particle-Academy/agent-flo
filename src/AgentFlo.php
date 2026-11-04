<?php

declare(strict_types=1);

namespace AgentFlo;

use AgentFlo\Approvals\Approvals;
use AgentFlo\Authorization\Authorizer;
use AgentFlo\Delegations\Delegation;
use AgentFlo\Delegations\DelegationStore;
use AgentFlo\Guide\FloMode;
use AgentFlo\Proposals\Proposals;
use AgentFlo\Store\FlowStore;
use AgentFlo\Store\RunStore;
use AgentFlo\Tools\FloTools;
use AgentFlo\Vocabulary\Vocabulary;
use FancyFlow\Authoring\FlowAuthoring;
use Illuminate\Contracts\Container\Container;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;

/**
 * The host-facing entry point. In v1 Flo runs one way: a declared subagent of a
 * mode on the prism-harness run loop.
 *
 *     'subagents' => AgentFlo::subagents('ops_agent', $vocabulary),   // in the parent mode
 *     'tools' => ['ops_agent'],                                         // required for a named agent
 *     app(AgentFlo::class)->bind($parentSession, FloScope::person($user->id, $team->id)); // each turn
 *
 * bind() is host code and runs before the parent's turn. It writes a fresh
 * delegation holding the scope and seeds the child's session with only its id.
 */
final class AgentFlo
{
    public const CAPABILITY = 'agent_flo';

    public function __construct(private readonly Container $app) {}

    /**
     * The subagent declaration for a parent mode, keyed by the one tool name the
     * parent sees.
     *
     * @param array<string,string> $vocabulary
     * @return array<string,array<string,mixed>>
     */
    public static function subagents(string $tool, array $vocabulary = [], int $maxSteps = 12): array
    {
        Vocabulary::assertDelegateToolName($tool, FloTools::allNames());
        $words = Vocabulary::fromArray($vocabulary);

        return [$tool => [
            'mode' => FloMode::NAME,
            'scope' => FloMode::SCOPE,
            'max_steps' => $maxSteps,
            'description' => $words->render('Ask {{Agent}} to find, read, check or change the app\'s {{flows}} and look into their {{runs}}. Give one complete instruction: {{Agent}} does not see this conversation. Changes come back as proposals a person approves, not as done.'),
        ]];
    }

    /** Called by host code each turn, before the parent runs. Returns the delegation id. */
    public function bind(Session $parent, FloScope $scope): string
    {
        $id = $this->delegate($scope);
        $this->app->make(PrismHarness::class)->forAgent($parent->agent())
            ->session($parent->participant(), $parent->scope().'::sub::'.FloMode::SCOPE, $parent->actor())
            ->usingCapability(self::CAPABILITY, ['delegation' => $id]);

        return $id;
    }

    /** A delegation with no harness session, for a host or test driving the tools directly. */
    public function delegate(FloScope $scope): string
    {
        return $this->delegations()->create($scope);
    }

    public function tools(string $delegation): FloTools
    {
        return new FloTools($this, $delegation);
    }

    /**
     * Which approvals this delegation's next change needs. Empty means it applies.
     *
     * @return list<string>
     */
    public function approvalsNeeded(Delegation $delegation): array
    {
        $approvals = $this->approvals();
        $why = [];
        if ($approvals->required('change')) {
            $why[] = 'change';
        }
        if ($delegation->tainted && $approvals->required('after_run_data')) {
            $why[] = 'after_run_data';
        }

        return $why;
    }

    public function preamble(): string
    {
        return FloMode::preamble($this->vocabulary());
    }

    public function delegations(): DelegationStore
    {
        return $this->app->make(DelegationStore::class);
    }

    public function proposals(): Proposals
    {
        return $this->app->make(Proposals::class);
    }

    public function approvals(): Approvals
    {
        return $this->app->make(Approvals::class);
    }

    public function vocabulary(): Vocabulary
    {
        return $this->app->make(Vocabulary::class);
    }

    public function authorizer(): Authorizer
    {
        return $this->app->make(Authorizer::class);
    }

    public function flows(): FlowStore
    {
        return $this->app->make(FlowStore::class);
    }

    public function runs(): RunStore
    {
        return $this->app->make(RunStore::class);
    }

    public function authoring(): FlowAuthoring
    {
        return $this->app->make(FlowAuthoring::class);
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('agent-flo.'.$key, $default);
    }
}
