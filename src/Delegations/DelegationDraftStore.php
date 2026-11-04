<?php

declare(strict_types=1);

namespace AgentFlo\Delegations;

use FancyFlow\Authoring\DraftWorkflow;
use FancyFlow\Mcp\Store\DraftStore;

/**
 * fancy-flow-mcp's draft store contract, held to ONE delegation. Its own stores
 * are tenantless and a miss lists every known id, which is why Flo never uses
 * them: here the only ids that exist are this delegation's own.
 */
final class DelegationDraftStore implements DraftStore
{
    public function __construct(private readonly DelegationStore $delegations, private readonly string $delegation) {}

    public function get(string $id): ?DraftWorkflow
    {
        $raw = $this->all()[$id] ?? null;

        return is_array($raw) ? DraftWorkflow::fromArray($raw) : null;
    }

    public function put(DraftWorkflow $draft): void
    {
        $drafts = $this->all();
        $drafts[$draft->id] = $draft->toArray();
        $this->delegations->putDrafts($this->delegation, $drafts);
    }

    public function has(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    public function forget(string $id): void
    {
        $drafts = $this->all();
        unset($drafts[$id]);
        $this->delegations->putDrafts($this->delegation, $drafts);
    }

    public function ids(): array
    {
        return array_map('strval', array_keys($this->all()));
    }

    /** @return array<string,array<string,mixed>> */
    private function all(): array
    {
        return $this->delegations->find($this->delegation)?->drafts ?? [];
    }
}
