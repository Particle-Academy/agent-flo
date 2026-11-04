<?php

declare(strict_types=1);

namespace AgentFlo\Proposals;

use AgentFlo\Authorization\Authorizer;
use AgentFlo\Delegations\Delegation;
use AgentFlo\FloScope;
use AgentFlo\Refused;
use AgentFlo\Store\FlowStore;
use AgentFlo\Store\StaleRevision;
use AgentFlo\Vocabulary\Vocabulary;
use FancyFlow\Analysis\TemplateReferences;
use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\Workflow;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Proposals: stored, shown and approved by code. No tool can approve; host code
 * calls {@see approve()} with a PERSON's scope. Approval re-validates against the
 * current revision and resolves `conflicted`, writing nothing, if anything moved.
 */
final class Proposals
{
    public const TABLE = 'flo_proposals';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FlowStore $flows,
        private readonly Authorizer $authorizer,
        private readonly Vocabulary $vocabulary,
        private readonly FlowAuthoring $authoring,
        private readonly int $ttlDays = 7,
    ) {}

    /** @param array<string,mixed> $schema */
    public function create(Delegation $delegation, ?string $target, ?string $basedOn, array $schema, ?string $rationale): Proposal
    {
        $current = $target === null ? null : $this->flows->find($delegation->scope, $target);
        $id = (string) Str::ulid();
        $now = Carbon::now();
        $row = [
            'id' => $id, 'tenant_key' => $delegation->scope->tenantKey(), 'delegation_id' => $delegation->id,
            'kind' => 'change', 'target' => $target, 'based_on' => $basedOn,
            'schema' => json_encode($schema), 'diff' => json_encode(self::diff($this->normalized($current?->schema ?? []), $this->normalized($schema))),
            'title' => $this->vocabulary->render($target === null ? 'New {{flow}}' : 'Change {{flow}}'),
            'rationale' => $rationale === null ? null : mb_substr($rationale, 0, 2000),
            'status' => Proposal::OPEN, 'created_scope' => json_encode($delegation->scope->toArray()),
            'expires_at' => $now->copy()->addDays($this->ttlDays), 'created_at' => $now, 'updated_at' => $now,
        ];
        $this->db->table(self::TABLE)->insert($row);

        return $this->find($id, $delegation->scope);
    }

    public function find(string $id, FloScope $scope): ?Proposal
    {
        $row = $this->db->table(self::TABLE)->where('id', $id)->where('tenant_key', $scope->tenantKey())->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function approve(string $id, FloScope $approver): Proposal
    {
        if ($approver->isSystem()) {
            throw new Refused('system_scope_cannot_approve');
        }
        $proposal = $this->find($id, $approver);
        if ($proposal === null || ! $this->authorizer->allows($approver, 'approve', $proposal->target)) {
            throw new Refused('not_found');
        }
        if ($proposal->status !== Proposal::OPEN) {
            throw new Refused('not_open');
        }
        $expires = $this->db->table(self::TABLE)->where('id', $id)->value('expires_at');
        if ($expires !== null && Carbon::now()->greaterThan($expires)) {
            $this->resolve($id, Proposal::EXPIRED, $approver);
            throw new Refused('expired');
        }

        return $this->db->transaction(function () use ($proposal, $approver): Proposal {
            // Re-validated with the one validation chain, at approval time.
            $import = Workflow::import($proposal->schema, registry: $this->authoring->kinds());
            if (! $import->ok) {
                return $this->resolve($proposal->id, Proposal::CONFLICTED, $approver);
            }
            try {
                $record = $proposal->target === null
                    ? $this->flows->create($approver, $proposal->schema)
                    : $this->flows->save($approver, $proposal->target, $proposal->schema, (string) $proposal->basedOn);
            } catch (StaleRevision) {
                return $this->resolve($proposal->id, Proposal::CONFLICTED, $approver);
            }

            return $this->resolve($proposal->id, Proposal::APPROVED, $approver, $record->id);
        });
    }

    public function reject(string $id, FloScope $scope): Proposal
    {
        if ($this->find($id, $scope) === null) {
            throw new Refused('not_found');
        }

        return $this->resolve($id, Proposal::REJECTED, $scope);
    }

    /**
     * Steps added, changed and removed, by id, computed by code.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array{added:list<string>,changed:list<string>,removed:list<string>}
     */
    public static function diff(array $before, array $after): array
    {
        $index = static function (array $schema): array {
            $out = [];
            foreach ($schema['graph']['nodes'] ?? [] as $node) {
                if (is_array($node) && is_string($node['id'] ?? null)) {
                    unset($node['position']);
                    $out[$node['id']] = $node;
                }
            }

            return $out;
        };
        $old = $index($before);
        $new = $index($after);

        return [
            'added' => array_values(array_map('strval', array_keys(array_diff_key($new, $old)))),
            'changed' => array_values(array_map('strval', array_keys(array_filter(array_intersect_key($new, $old), fn ($n, $id) => $n != $old[$id], ARRAY_FILTER_USE_BOTH)))),
            'removed' => array_values(array_map('strval', array_keys(array_diff_key($old, $new)))),
        ];
    }

    /**
     * Both sides through the same import and export, so a step reads as changed
     * only when it changed: a draft comes back with defaults and labels filled in.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function normalized(array $schema): array
    {
        if ($schema === []) {
            return [];
        }
        $import = Workflow::import($schema, lenient: true, registry: $this->authoring->kinds(), templateReferences: TemplateReferences::OFF);

        return Workflow::export($import->graph);
    }

    private function resolve(string $id, string $status, FloScope $by, ?string $target = null): Proposal
    {
        $update = ['status' => $status, 'resolved_scope' => json_encode($by->toArray()), 'resolved_at' => Carbon::now(), 'updated_at' => Carbon::now()];
        if ($target !== null) {
            $update['target'] = $target;
        }
        $this->db->table(self::TABLE)->where('id', $id)->update($update);

        return $this->find($id, $by);
    }

    private static function hydrate(object $row): Proposal
    {
        return new Proposal(
            (string) $row->id, (string) $row->kind, $row->target === null ? null : (string) $row->target,
            $row->based_on === null ? null : (string) $row->based_on,
            (array) json_decode((string) $row->schema, true), (array) json_decode((string) $row->diff, true),
            (string) $row->title, $row->rationale === null ? null : (string) $row->rationale,
            (string) $row->status, (string) $row->tenant_key, (string) $row->delegation_id,
        );
    }
}
