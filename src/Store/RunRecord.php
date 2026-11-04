<?php

declare(strict_types=1);

namespace AgentFlo\Store;

final readonly class RunRecord
{
    /** @param array<string,mixed> $outputs */
    public function __construct(
        public string $id,
        public ?string $flowId,
        public string $status,
        public ?string $error = null,
        public array $outputs = [],
        public ?string $startedAt = null,
    ) {}
}
