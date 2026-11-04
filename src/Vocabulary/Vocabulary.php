<?php

declare(strict_types=1);

namespace AgentFlo\Vocabulary;

use LogicException;
use Normalizer;

/**
 * The host's words for the agent and the things it manages.
 *
 * These reach a model prompt, so the values are developer config, per app, and
 * refused at boot when they break the pattern. 32 characters can still spell a
 * short instruction: the real control is that tenant and end-user text must never
 * flow in here. The pattern reduces mistakes; it is not a sanitiser.
 */
final class Vocabulary
{
    public const DEFAULTS = [
        'agent' => 'Flo',
        'flow' => 'flow', 'flows' => 'flows',
        'step' => 'step', 'steps' => 'steps',
        'run' => 'run', 'runs' => 'runs',
    ];

    /**
     * Linear by construction: one character class, one bounded repetition, no
     * alternation or nesting. Unicode letters, digits, space, hyphen, apostrophe,
     * starting with a letter, 1 to 32 characters.
     */
    private const WORD = "/^\\p{L}[\\p{L}\\p{N} '\\-]{0,31}$/u";

    private const TOOL = '/^[a-z][a-z0-9_]{0,62}$/';

    /** @param array<string,string> $words */
    private function __construct(private readonly array $words) {}

    /** @param array<string,mixed> $config */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new InvalidVocabulary("agent-flo.vocabulary has an unknown key [{$key}]. Known: ".implode(', ', array_keys(self::DEFAULTS)).'.');
            }
        }

        $words = [];
        foreach (self::DEFAULTS as $key => $default) {
            $words[$key] = self::assertWord($key, $config[$key] ?? $default);
        }

        return new self($words);
    }

    public static function assertWord(string $key, mixed $value): string
    {
        $problem = match (true) {
            ! is_string($value) => 'is not a string',
            strlen($value) > 128 => 'is longer than 32 characters',
            preg_match(self::WORD, $value) !== 1 => 'must start with a letter and hold only letters, digits, spaces, hyphens and apostrophes, at most 32 characters',
            ! Normalizer::isNormalized($value, Normalizer::FORM_C) => 'is not in Unicode NFC form',
            count(explode(' ', trim($value))) > 3 => 'is more than three words',
            default => null,
        };

        if ($problem !== null) {
            throw new InvalidVocabulary("agent-flo.vocabulary.{$key} {$problem}.");
        }

        return $value;
    }

    /**
     * The name of the one tool a parent agent sees. Flo's own tools stay fixed.
     *
     * @param list<string> $reserved
     */
    public static function assertDelegateToolName(string $name, array $reserved = []): string
    {
        if (preg_match(self::TOOL, $name) !== 1 || str_starts_with($name, 'flo_') || in_array($name, $reserved, true)) {
            throw new InvalidVocabulary("The delegate tool name [{$name}] must be a lowercase letter then lowercase letters, digits or underscores, at most 63 characters, and must not start with flo_ or reuse one of Flo's tool names.");
        }

        return $name;
    }

    public function word(string $key): string
    {
        return $this->words[$key] ?? throw new LogicException("No vocabulary word [{$key}].");
    }

    /**
     * One pass over a closed set: `{{agent}}`, `{{flow}}`, `{{flows}}`, `{{step}}`,
     * `{{steps}}`, `{{run}}`, `{{runs}}`, and each capitalised. An unknown
     * placeholder is a bug in our template and fails loudly.
     */
    public function render(string $template): string
    {
        return (string) preg_replace_callback('/\{\{([A-Za-z]{1,8})\}\}/', function (array $m): string {
            $key = $m[1];
            $lower = lcfirst($key);
            if (! isset($this->words[$lower])) {
                throw new LogicException("Unknown vocabulary placeholder {{{$key}}}.");
            }
            $word = $this->words[$lower];

            return $key === $lower ? $word : mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
        }, $template);
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return $this->words;
    }

    public function hash(): string
    {
        return substr(hash('sha256', (string) json_encode($this->words)), 0, 16);
    }
}
