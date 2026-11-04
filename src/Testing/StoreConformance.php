<?php

declare(strict_types=1);

namespace AgentFlo\Testing;

use AgentFlo\FloScope;
use AgentFlo\Store\StaleRevision;
use Throwable;

/**
 * The check that makes "any host" real. A host runs it against its own adapter
 * and asserts the result is empty:
 *
 *     expect(StoreConformance::run(new MyFixture))->toBe([]);
 *
 * Returns the names of the rules that failed. Framework-free on purpose, so it
 * runs under any test runner.
 */
final class StoreConformance
{
    /** @return list<string> */
    public static function run(StoreFixture $fixture): array
    {
        $tag = bin2hex(random_bytes(4));
        $a = FloScope::person('conformance-a', 'conformance-a-'.$tag);
        $b = FloScope::person('conformance-b', 'conformance-b-'.$tag);
        $schema = ['version' => 1, 'metadata' => ['name' => 'conformance'], 'graph' => ['nodes' => [], 'edges' => []]];

        $checks = [
            'a flow in another tenant is invisible' => function () use ($fixture, $a, $b, $schema): bool {
                $id = $fixture->seedFlow($a, $schema);

                return $fixture->flows()->find($a, $id) !== null && $fixture->flows()->find($b, $id) === null;
            },
            'a missing and a forbidden flow are the same answer' => function () use ($fixture, $a, $b, $schema): bool {
                $id = $fixture->seedFlow($a, $schema);

                return $fixture->flows()->find($b, $id) === $fixture->flows()->find($b, 'missing-'.bin2hex(random_bytes(4)));
            },
            'a shared flow is visible to every tenant' => function () use ($fixture, $a, $b, $schema): bool {
                $id = $fixture->seedFlow(null, $schema);

                return $fixture->flows()->find($a, $id) !== null && $fixture->flows()->find($b, $id) !== null;
            },
            'a run in another tenant is invisible, even of a flow both can see' => function () use ($fixture, $a, $b, $schema): bool {
                $run = $fixture->seedRun($a, $fixture->seedFlow(null, $schema));

                return $fixture->runs()->run($a, $run) !== null && $fixture->runs()->run($b, $run) === null;
            },
            'a list shows only what the tenant may see' => function () use ($fixture, $a, $b, $schema): bool {
                $id = $fixture->seedFlow($a, $schema);
                $ids = array_map(fn ($f) => $f->id, $fixture->flows()->list($b, null, 1000)['items']);

                return ! in_array($id, $ids, true);
            },
            'a list of runs shows only the tenant\'s runs' => function () use ($fixture, $a, $b, $schema): bool {
                $run = $fixture->seedRun($a, $fixture->seedFlow(null, $schema));

                return ! in_array($run, array_map(fn ($r) => $r->id, $fixture->runs()->runs($b, null, 1000)), true);
            },
            'a write on a stale revision refuses and writes nothing' => function () use ($fixture, $a, $schema): bool {
                $id = $fixture->seedFlow($a, $schema);
                $before = $fixture->flows()->find($a, $id);
                try {
                    $fixture->flows()->save($a, $id, ['changed' => true] + $schema, 'stale');

                    return false;
                } catch (StaleRevision) {
                }
                $after = $fixture->flows()->find($a, $id);
                $saved = $fixture->flows()->save($a, $id, ['metadata' => ['name' => 'moved']] + $schema, $before->revision);

                return $after == $before && $saved->revision !== $before->revision;
            },
            'a write into another tenant refuses and writes nothing' => function () use ($fixture, $a, $b, $schema): bool {
                $id = $fixture->seedFlow($a, $schema);
                $before = $fixture->flows()->find($a, $id);
                try {
                    $fixture->flows()->save($b, $id, ['metadata' => ['name' => 'hijack']] + $schema, $before->revision);

                    return false;
                } catch (StaleRevision) {
                }

                return $fixture->flows()->find($a, $id) == $before;
            },
        ];

        $failed = [];
        foreach ($checks as $name => $check) {
            try {
                if ($check() !== true) {
                    $failed[] = $name;
                }
            } catch (Throwable) {
                $failed[] = $name;
            }
        }

        return $failed;
    }
}
