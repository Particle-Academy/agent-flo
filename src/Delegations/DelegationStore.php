<?php

declare(strict_types=1);

namespace AgentFlo\Delegations;

use AgentFlo\FloScope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class DelegationStore
{
    public const TABLE = 'flo_delegations';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(FloScope $scope): string
    {
        $id = (string) Str::ulid();
        $now = Carbon::now();
        $this->db->table(self::TABLE)->insert([
            'id' => $id, 'tenant_key' => $scope->tenantKey(), 'scope' => json_encode($scope->toArray()),
            'tainted' => false, 'tools' => '[]', 'drafts' => '{}', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    public function find(string $id): ?Delegation
    {
        $row = $this->db->table(self::TABLE)->where('id', $id)->first();
        if ($row === null) {
            return null;
        }

        return new Delegation(
            (string) $row->id,
            FloScope::fromArray((array) json_decode((string) $row->scope, true)),
            (bool) $row->tainted,
            (array) json_decode((string) $row->tools, true),
            (array) json_decode((string) $row->drafts, true),
        );
    }

    /** The first tool result carrying run data marks it, and nothing unmarks it. */
    public function taint(string $id): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update(['tainted' => true, 'updated_at' => Carbon::now()]);
    }

    public function recordTool(string $id, string $tool): void
    {
        $this->db->transaction(function () use ($id, $tool): void {
            $row = $this->db->table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $tools = (array) json_decode((string) $row->tools, true);
            $tools[] = $tool;
            $this->db->table(self::TABLE)->where('id', $id)->update(['tools' => json_encode($tools), 'updated_at' => Carbon::now()]);
        });
    }

    /** @param array<string,array<string,mixed>> $drafts */
    public function putDrafts(string $id, array $drafts): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update(['drafts' => json_encode((object) $drafts), 'updated_at' => Carbon::now()]);
    }
}
