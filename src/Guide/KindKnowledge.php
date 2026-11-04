<?php

declare(strict_types=1);

namespace AgentFlo\Guide;

use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\Registry\KindId;

/**
 * The kind facts in Flo's prompt, GENERATED from the app's registry on every
 * build. Nothing here is a hand-kept list, so a host kind appears the moment it
 * is registered and a builtin that changes its shape changes the prompt with it.
 *
 * Facts only. Descriptions are left out: a host or vendored kind's description
 * is text someone else wrote, and it would sit in the instruction stream.
 */
final class KindKnowledge
{
    public static function render(FlowAuthoring $authoring): string
    {
        $lines = [];
        $kinds = $authoring->kinds()->all();
        usort($kinds, static fn ($a, $b): int => strcmp(KindId::bare($a->name), KindId::bare($b->name)));

        foreach ($kinds as $kind) {
            $described = $authoring->describeKind($kind);
            $fields = $described['emits']['fields'];
            $relation = $described['emits']['relation'];
            $emits = match (true) {
                $fields === null && $relation !== null => "carries its input ({$relation})",
                $fields === null => 'fields not declared: unknown, so never treat a reference as wrong on that basis',
                $fields === [] => 'declares no fields',
                default => 'fields '.implode(', ', array_column($fields, 'path')).($relation !== null ? " plus its input ({$relation})" : ''),
            };
            if ($described['emits']['configDependent']) {
                $emits .= ', depends on config';
            }
            $parts = [
                sprintf('- `%s` (%s)', KindId::bare($kind->name), $kind->category),
                'in: '.self::ports($described['ports']['inputs']),
                'out: '.self::ports($described['ports']['outputs']),
                $emits,
                're-run cost: '.($kind->sideEffects ?? 'not declared'),
            ];
            if ($kind->pausesForHuman !== null) {
                $parts[] = 'waits for a person ('.$kind->pausesForHuman.')';
            }
            $lines[] = implode('; ', $parts);
        }

        return implode("\n", $lines);
    }

    /** @param list<string> $ports */
    private static function ports(array $ports): string
    {
        return $ports === [] ? 'none' : implode(', ', $ports);
    }
}
