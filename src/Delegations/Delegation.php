<?php

declare(strict_types=1);

namespace AgentFlo\Delegations;

use AgentFlo\FloScope;

/**
 * One parent turn's hand-off to Flo. Its scope is written by host code each turn
 * and re-read on every tool call. `tainted` and `tools` are written by code only,
 * never by a model, so a host decides from them rather than from the child's text.
 */
final readonly class Delegation
{
    /**
     * @param list<string> $tools tool names called, in order
     * @param array<string,array<string,mixed>> $drafts
     */
    public function __construct(
        public string $id,
        public FloScope $scope,
        public bool $tainted,
        public array $tools,
        public array $drafts,
    ) {}
}
