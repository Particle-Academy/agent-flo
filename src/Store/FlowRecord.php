<?php

declare(strict_types=1);

namespace AgentFlo\Store;

final readonly class FlowRecord
{
    /** @param array<string,mixed> $schema */
    public function __construct(
        public string $id,
        public string $revision,
        public array $schema,
        public ?string $name = null,
    ) {}
}
