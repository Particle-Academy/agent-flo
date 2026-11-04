<?php

declare(strict_types=1);

namespace AgentFlo\Testing;

use AgentFlo\FloScope;
use AgentFlo\Store\FlowStore;
use AgentFlo\Store\RunStore;

/** What a host writes so {@see StoreConformance} can run against its own tables. */
interface StoreFixture
{
    public function flows(): FlowStore;

    public function runs(): RunStore;

    /**
     * Seed a flow in `$owner`'s tenant, or a SHARED flow every tenant sees when null.
     *
     * @param array<string,mixed> $schema
     */
    public function seedFlow(?FloScope $owner, array $schema): string;

    /** Seed a run of `$flowId` that belongs to `$owner`'s tenant. */
    public function seedRun(FloScope $owner, string $flowId): string;
}
