<?php

declare(strict_types=1);

namespace AgentFlo\Approvals;

/**
 * The approval-override pattern every agent package follows.
 *
 * 1. Every approval is ON by default.
 * 2. Off is one setting whose name says what it does:
 *    `agent-flo.dangerously_disable_approvals`, holding `true` (all) or a list
 *    of names. An unknown name stops boot, because a typo there means the
 *    developer believes something is off that is not.
 * 3. While anything is off, {@see warnings()} names each approval, and the
 *    service provider writes them into `package:discover` and `optimize` output,
 *    so a deploy log carries them every time.
 * 4. `php artisan agent-flo:approvals --ci` exits non-zero while anything is off,
 *    for a host that wants to fail its build on it.
 *
 * Off means off, including `after_run_data`: the developer decides. The same
 * names are declared in `resources/hitl.json` for the kit-wide HITL map.
 *
 * Small on purpose, so another agent package copies it with its own DECLARED.
 */
final class Approvals
{
    public const PACKAGE = 'agent-flo';

    public const SETTING = 'agent-flo.dangerously_disable_approvals';

    /** @var array<string,string> name => what a person approves while it is on */
    public const DECLARED = [
        'change' => 'Creating or changing a flow is a proposal a person approves.',
        'after_run_data' => 'Once a delegation has read run data (outputs, logs, errors), everything it does is a proposal, whatever else is off.',
    ];

    /** @param list<string> $off */
    private function __construct(private readonly array $off) {}

    public static function fromConfig(mixed $value): self
    {
        if ($value === null || $value === false || $value === [] || $value === '') {
            return new self([]);
        }
        if ($value === true || $value === 'true' || $value === 'all' || $value === '*') {
            return new self(array_keys(self::DECLARED));
        }
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidApprovals(self::SETTING.' must be true or a list of approval names.');
        }

        $off = [];
        foreach ($value as $name) {
            if (! is_string($name) || ! array_key_exists($name, self::DECLARED)) {
                throw new InvalidApprovals(self::SETTING.' names an unknown approval ['.(is_scalar($name) ? (string) $name : gettype($name)).']. Known: '.implode(', ', array_keys(self::DECLARED)).'.');
            }
            $off[$name] = true;
        }

        // Declared order, so the output is stable.
        return new self(array_values(array_filter(array_keys(self::DECLARED), fn (string $n): bool => isset($off[$n]))));
    }

    public function required(string $name): bool
    {
        if (! array_key_exists($name, self::DECLARED)) {
            throw new InvalidApprovals("No approval named [{$name}].");
        }

        return ! in_array($name, $this->off, true);
    }

    /** @return list<string> */
    public function off(): array
    {
        return $this->off;
    }

    /** @return list<string> one line per approval that is off */
    public function warnings(): array
    {
        return array_map(
            fn (string $name): string => sprintf('WARNING %s: approval [%s] is OFF via %s. No longer true: %s', self::PACKAGE, $name, self::SETTING, self::DECLARED[$name]),
            $this->off,
        );
    }
}
