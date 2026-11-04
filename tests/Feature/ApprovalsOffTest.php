<?php

declare(strict_types=1);

use AgentFlo\AgentFlo;
use AgentFlo\Approvals\Approvals;
use AgentFlo\Approvals\InvalidApprovals;
use AgentFlo\Store\FlowStore;
use AgentFlo\Store\InMemoryStore;
use AgentFlo\Store\RunStore;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('has every approval on by default', function (): void {
    $approvals = Approvals::fromConfig(null);

    expect($approvals->off())->toBe([])
        ->and($approvals->required('change'))->toBeTrue()
        ->and($approvals->required('after_run_data'))->toBeTrue()
        ->and($approvals->warnings())->toBe([]);
});

it('turns off only what is named, or everything with true', function (): void {
    expect(Approvals::fromConfig(['change'])->off())->toBe(['change'])
        ->and(Approvals::fromConfig(true)->off())->toBe(['change', 'after_run_data'])
        ->and(Approvals::fromConfig(false)->off())->toBe([]);
});

it('names each approval that is off in its warning', function (): void {
    $warnings = Approvals::fromConfig(true)->warnings();

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('agent-flo')->toContain('change')
        ->and($warnings[1])->toContain('after_run_data');
});

it('refuses an unknown approval name at boot rather than ignoring it', function (): void {
    expect(fn () => Approvals::fromConfig(['chnage']))->toThrow(InvalidApprovals::class)
        ->and(fn () => floApp(['agent-flo.dangerously_disable_approvals' => ['chnage']]))->toThrow(InvalidApprovals::class);
});

it('applies a change directly when both approvals are off', function (): void {
    $app = floApp(['agent-flo.dangerously_disable_approvals' => true]);
    $store = new InMemoryStore;
    $app->instance(FlowStore::class, $store);
    $app->instance(RunStore::class, $store);
    $id = $store->seedFlow('acme', sampleSchema());
    $run = $store->seedRun('acme', $id, 'failed');
    $d = app(AgentFlo::class)->delegate(scopeA());
    floTool($d, 'flo_run', ['run_id' => $run]);
    $flow = draftChange($d, $id);

    $result = floTool($d, 'flo_propose', ['workflow_id' => 'edit', 'flow_id' => $id, 'based_on' => $flow['revision']]);

    expect($result['applied'])->toBeTrue()
        ->and($store->find(scopeA(), $id)->revision)->toBe($result['revision']);
});

function artisan(array $config, string $command, array $args = []): array
{
    // The command itself, without the console kernel's bootstrap, which installs a
    // process-wide error handler. A real `php artisan` run is in the handover.
    $app = floApp($config);
    $output = new BufferedOutput;
    $instance = $app->make(\AgentFlo\Laravel\ApprovalsCommand::class);
    $instance->setLaravel($app);
    expect($instance->getName())->toBe($command);
    $code = $instance->run(new ArrayInput($args), $output);

    return [$code, $output->fetch()];
}

it('reports state from the command and exits 0 without --ci', function (): void {
    [$code, $out] = artisan(['agent-flo.dangerously_disable_approvals' => ['change']], 'agent-flo:approvals');

    expect($code)->toBe(0)
        ->and($out)->toContain('WARNING')->toContain('change')->toContain('after_run_data: on');
});

it('exits non-zero with --ci when any approval is off, and zero when none is', function (): void {
    expect(artisan(['agent-flo.dangerously_disable_approvals' => ['after_run_data']], 'agent-flo:approvals', ['--ci' => true])[0])->toBe(1)
        ->and(artisan([], 'agent-flo:approvals', ['--ci' => true])[0])->toBe(0);
});

it('warns in package:discover output when an approval is off, and says nothing when none is', function (): void {
    foreach ([[true, true], [[], false]] as [$off, $warns]) {
        $app = floApp(['agent-flo.dangerously_disable_approvals' => $off]);
        $output = new BufferedOutput;
        $app['events']->dispatch(new CommandFinished('package:discover', new ArrayInput([]), $output, 0));

        expect(str_contains($output->fetch(), 'WARNING'))->toBe($warns);
    }
});

it('registers the command with php artisan optimize', function (): void {
    floApp();

    expect(\Illuminate\Support\ServiceProvider::$optimizeCommands)->toContain('agent-flo:approvals');
});

it('declares its approvals in a machine-readable file that matches the code', function (): void {
    $declared = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/hitl.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($declared['approvals'], 'name'))->toBe(array_keys(Approvals::DECLARED))
        ->and($declared['package'])->toBe('particle-academy/agent-flo')
        ->and($declared['disable_with'])->toBe('agent-flo.dangerously_disable_approvals');
    foreach ($declared['approvals'] as $row) {
        expect($row['default'])->toBe('required')->and($row['can_disable'])->toBeTrue();
    }
});
