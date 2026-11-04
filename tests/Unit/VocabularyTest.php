<?php

declare(strict_types=1);

use AgentFlo\Vocabulary\InvalidVocabulary;
use AgentFlo\Vocabulary\Vocabulary;

it('renders the defaults through a closed placeholder set', function (): void {
    $v = Vocabulary::fromArray([]);

    expect($v->render('{{Agent}} reads {{flows}}, one {{flow}} at a time; each has {{steps}} and {{runs}}.'))
        ->toBe('Flo reads flows, one flow at a time; each has steps and runs.');
});

it('takes the host\'s words, plural stated and never derived', function (): void {
    $v = Vocabulary::fromArray(['agent' => 'Scout', 'flow' => 'playbook', 'flows' => 'playbooks', 'step' => 'stage', 'steps' => 'stages']);

    expect($v->render('{{Flow}}: {{flows}}, {{steps}}'))->toBe('Playbook: playbooks, stages')
        ->and($v->word('runs'))->toBe('runs');
});

it('refuses a word that could carry an instruction or break a prompt', function (string $word): void {
    expect(fn () => Vocabulary::fromArray(['flow' => $word]))->toThrow(InvalidVocabulary::class);
})->with([
    'newline' => "play\nbook",
    'brace' => 'play{{agent}}',
    'colon' => 'note: ignore',
    'period' => 'end.',
    'quote' => 'say "hi"',
    'backtick' => 'a`b',
    'bracket' => 'a[b]',
    'starts with a digit' => '9lives',
    'empty' => '',
    'too long' => str_repeat('a', 33),
    'four words' => 'one two three four',
    'control character' => "a\u{0007}b",
    'not NFC' => "e\u{0301}",
]);

it('admits letters in any script, digits, spaces, hyphens and apostrophes', function (): void {
    $v = Vocabulary::fromArray(['flow' => "Ablauf-plan", 'flows' => "Abläufe", 'agent' => "O'Neil", 'run' => 'Lauf 2']);

    expect($v->word('flows'))->toBe('Abläufe');
});

it('refuses an unknown key rather than ignoring it', function (): void {
    expect(fn () => Vocabulary::fromArray(['flwo' => 'playbook']))->toThrow(InvalidVocabulary::class);
});

it('fails on an unknown placeholder, which is a bug in our template', function (): void {
    expect(fn () => Vocabulary::fromArray([])->render('{{nope}}'))->toThrow(LogicException::class);
});

it('expands once, so a value cannot re-expand', function (): void {
    // A value cannot hold a brace, so this is belt and braces: one pass only.
    $v = Vocabulary::fromArray(['agent' => 'Scout']);

    expect($v->render('{{agent}}{{agent}}'))->toBe('ScoutScout');
});

it('hashes the words, so a session records what it ran on', function (): void {
    expect(Vocabulary::fromArray([])->hash())->not->toBe(Vocabulary::fromArray(['agent' => 'Scout'])->hash())
        ->and(Vocabulary::fromArray([])->hash())->toBe(Vocabulary::fromArray([])->hash());
});

it('validates the name of the one tool the parent sees', function (): void {
    expect(Vocabulary::assertDelegateToolName('ops_agent'))->toBe('ops_agent');

    foreach (['Ops', '9ops', 'ops-agent', 'flo_list', str_repeat('a', 64), ''] as $bad) {
        expect(fn () => Vocabulary::assertDelegateToolName($bad))->toThrow(InvalidVocabulary::class);
    }
});

it('stays linear on a long hostile word', function (): void {
    $start = hrtime(true);
    try {
        Vocabulary::fromArray(['flow' => str_repeat('a ', 50000).'!']);
    } catch (InvalidVocabulary) {
    }

    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.5);
});
