<?php

declare(strict_types=1);

use AgentFlo\FloScope;

it('names a person and a tenant, always from code', function (): void {
    $scope = FloScope::person('ada', 'acme');

    expect($scope->isSystem())->toBeFalse()
        ->and($scope->tenantKey())->toBe('acme')
        ->and($scope->actor)->toBe('ada');
});

it('records acting for another tenant explicitly, on the scope', function (): void {
    $scope = FloScope::person('ada', 'acme')->actingFor('globex');

    expect($scope->tenantKey())->toBe('globex')
        ->and($scope->tenant)->toBe('acme')
        ->and($scope->toArray()['actingFor'])->toBe('globex');
});

it('carries the run a system scope acts inside', function (): void {
    $scope = FloScope::system('scheduler', 'acme', 'run_1');

    expect($scope->isSystem())->toBeTrue()
        ->and($scope->run)->toBe('run_1');
});

it('round-trips through storage unchanged', function (): void {
    $scope = FloScope::system('scheduler', 'acme', 'run_1')->actingFor('globex');

    expect(FloScope::fromArray($scope->toArray()))->toEqual($scope);
});

it('refuses an empty tenant: there is no ambient one', function (): void {
    expect(fn () => FloScope::person('ada', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FloScope::person('', 'acme'))->toThrow(InvalidArgumentException::class);
});
