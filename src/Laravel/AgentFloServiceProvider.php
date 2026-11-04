<?php

declare(strict_types=1);

namespace AgentFlo\Laravel;

use AgentFlo\AgentFlo;
use AgentFlo\Approvals\Approvals;
use AgentFlo\Authorization\Authorizer;
use AgentFlo\Authorization\DenyAll;
use AgentFlo\Delegations\DelegationStore;
use AgentFlo\Guide\FloMode;
use AgentFlo\Proposals\Proposals;
use AgentFlo\Store\FlowStore;
use AgentFlo\Store\RunStore;
use AgentFlo\Store\StoreNotConfigured;
use AgentFlo\Tools\FloTools;
use AgentFlo\Vocabulary\Vocabulary;
use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\NodeKindRegistry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;

final class AgentFloServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/agent-flo.php', 'agent-flo');

        $this->app->singleton(Vocabulary::class, fn (Application $app) => Vocabulary::fromArray((array) $app['config']->get('agent-flo.vocabulary', [])));
        $this->app->singleton(Approvals::class, fn (Application $app) => Approvals::fromConfig($app['config']->get(Approvals::SETTING)));
        $this->app->singletonIf(Authorizer::class, fn () => new DenyAll);
        // Refused until the host chooses: the default tables have no tenant column.
        $this->app->bindIf(FlowStore::class, fn () => throw StoreNotConfigured::make());
        $this->app->bindIf(RunStore::class, fn () => throw StoreNotConfigured::make());
        $this->app->singleton(DelegationStore::class, fn (Application $app) => new DelegationStore($app['db']->connection()));
        $this->app->bind(Proposals::class, fn (Application $app) => new Proposals(
            $app['db']->connection(), $app->make(FlowStore::class), $app->make(Authorizer::class),
            $app->make(Vocabulary::class), $app->make(FlowAuthoring::class),
            (int) $app['config']->get('agent-flo.proposal_ttl_days', 7),
        ));
        $this->app->singleton(AgentFlo::class, fn (Application $app) => new AgentFlo($app));

        // The mode, merged into prism-harness's modes when the registry is first
        // built, after host kinds are registered. A host mode of that name wins.
        $this->app->extend(ModeRegistry::class, function (ModeRegistry $registry, Application $app): ModeRegistry {
            $agent = (array) $app['config']->get('prism-harness.agent', []);
            $modes = is_array($agent['modes'] ?? null) ? $agent['modes'] : [];
            if (! isset($modes[FloMode::NAME])) {
                $authoring = FlowAuthoring::withHostKinds($app->bound(NodeKindRegistry::class) ? $app->make(NodeKindRegistry::class) : null);
                $modes[FloMode::NAME] = FloMode::config($app->make(Vocabulary::class), $authoring, (int) $app['config']->get('agent-flo.max_steps', 12));
            }
            $agent['modes'] = $modes;

            return new ModeRegistry($agent);
        });
    }

    public function boot(): void
    {
        // Resolved now so a bad word or an unknown approval name stops boot.
        $this->app->make(Vocabulary::class);
        $approvals = $this->app->make(Approvals::class);

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ApprovalsCommand::class]);
            $this->publishes([dirname(__DIR__, 2).'/config/agent-flo.php' => $this->app->configPath('agent-flo.php')], 'agent-flo-config');
        }
        // Runs inside `php artisan optimize`, which deploy scripts call.
        $this->optimizes(optimize: 'agent-flo:approvals');

        // `package:discover` runs on every `composer install` of a Laravel app, so
        // its output is in every deploy log. Warn there too. It never fails a build.
        if ($approvals->off() !== []) {
            $this->app['events']->listen(CommandFinished::class, function (CommandFinished $event) use ($approvals): void {
                if ($event->command === 'package:discover') {
                    foreach ($approvals->warnings() as $warning) {
                        $event->output->writeln('<comment>'.$warning.'</comment>');
                    }
                }
            });
        }

        // Tools bind through harness capability state that only host code writes.
        // The model never names its scope, tenant or itself.
        $this->app->make(ToolRegistry::class)->registerProvider(function (Session $session): array {
            if ($session->mode() !== FloMode::NAME || ! str_ends_with($session->scope(), '::sub::'.FloMode::SCOPE)) {
                return [];
            }
            $id = $session->capability(AgentFlo::CAPABILITY)['delegation'] ?? null;
            if (! is_string($id) || $this->app->make(DelegationStore::class)->find($id) === null) {
                return [];
            }

            return (new FloTools($this->app->make(AgentFlo::class), $id))->prismTools();
        });
    }
}
