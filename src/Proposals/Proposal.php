<?php

declare(strict_types=1);

namespace AgentFlo\Proposals;

/**
 * A change the agent asked for and a person decides. Every field but
 * `rationale` is written by code; `rationale` is the model's and is untrusted.
 */
final readonly class Proposal
{
    public const OPEN = 'open';
    public const APPROVED = 'approved';
    public const CONFLICTED = 'conflicted';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';

    /**
     * @param array<string,mixed> $schema
     * @param array{added:list<string>,changed:list<string>,removed:list<string>} $diff
     */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $target,
        public ?string $basedOn,
        public array $schema,
        public array $diff,
        public string $title,
        public ?string $rationale,
        public string $status,
        public string $tenantKey,
        public string $delegation,
    ) {}
}
