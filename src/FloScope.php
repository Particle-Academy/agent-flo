<?php

declare(strict_types=1);

namespace AgentFlo;

use InvalidArgumentException;

/**
 * Who a call is for, and inside which tenant. Built by HOST CODE on every call:
 * never a tool argument, never inferred, never `Auth::user()`. Inside a run the
 * signed-in user is whoever the run executes as, so ambient auth is the wrong
 * answer by construction.
 *
 * Two surfaces. A `person` is someone using the app. A `system` scope acts
 * inside a run (a flow can call an agent), and is read-only unless the host
 * configures otherwise. Acting for another tenant is explicit and recorded.
 */
final readonly class FloScope
{
    private function __construct(
        public string $kind,
        public string $actor,
        public string $tenant,
        public ?string $run = null,
        public ?string $actingFor = null,
    ) {
        if ($actor === '' || $tenant === '' || $actingFor === '') {
            throw new InvalidArgumentException('A scope needs a non-empty actor and tenant.');
        }
    }

    public static function person(string|int $actor, string|int $tenant): self
    {
        return new self('person', (string) $actor, (string) $tenant);
    }

    public static function system(string|int $runAs, string|int $tenant, string $run): self
    {
        return new self('system', (string) $runAs, (string) $tenant, $run);
    }

    public function actingFor(string|int $tenant): self
    {
        return new self($this->kind, $this->actor, $this->tenant, $this->run, (string) $tenant);
    }

    public function isSystem(): bool
    {
        return $this->kind === 'system';
    }

    /** The tenant every store read and write is held to. */
    public function tenantKey(): string
    {
        return $this->actingFor ?? $this->tenant;
    }

    /** @return array{kind:string,actor:string,tenant:string,run:?string,actingFor:?string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'actor' => $this->actor, 'tenant' => $this->tenant, 'run' => $this->run, 'actingFor' => $this->actingFor];
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $kind = ($raw['kind'] ?? null) === 'system' ? 'system' : 'person';

        return new self(
            $kind,
            (string) ($raw['actor'] ?? ''),
            (string) ($raw['tenant'] ?? ''),
            isset($raw['run']) ? (string) $raw['run'] : null,
            isset($raw['actingFor']) ? (string) $raw['actingFor'] : null,
        );
    }
}
