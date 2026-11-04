<?php

declare(strict_types=1);

use AgentFlo\AgentFlo;
use AgentFlo\Authorization\CallbackAuthorizer;
use AgentFlo\Authorization\Authorizer;
use AgentFlo\FloScope;
use AgentFlo\Tests\Support\ScriptedProvider;
use AgentFlo\Tests\Support\TestApp;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

uses()->afterEach(function (): void {
    Facade::clearResolvedInstances();
    Container::setInstance(null);
})->in(__DIR__);

/** A booted app. `allow` binds an authorizer that allows every ability. */
function floApp(array $config = [], ?ScriptedProvider $provider = null, bool $allow = true): Application
{
    $app = TestApp::boot($config, $provider);
    if ($allow) {
        $app->instance(Authorizer::class, new CallbackAuthorizer(fn (): bool => true));
    }

    return $app;
}

function scopeA(): FloScope
{
    return FloScope::person('ada', 'acme');
}

function scopeB(): FloScope
{
    return FloScope::person('bob', 'globex');
}

/** A three-step graph: trigger -> llm_call -> log. */
function sampleSchema(string $template = '{{ in.text }}'): array
{
    return [
        'version' => 1,
        'metadata' => ['name' => 'Nightly digest'],
        'graph' => [
            'nodes' => [
                ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]],
                ['id' => 'a', 'kind' => 'llm_call', 'position' => ['x' => 1, 'y' => 0], 'config' => ['model' => 'm', 'prompt' => 'x']],
                ['id' => 'o', 'kind' => 'log', 'position' => ['x' => 2, 'y' => 0], 'config' => ['message' => $template]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't', 'target' => 'a'],
                ['id' => 'e2', 'source' => 'a', 'target' => 'o'],
            ],
        ],
    ];
}

/** Call one of a delegation's tools the way the model does, decoded. */
function floTool(string $delegation, string $name, array $args = []): array
{
    return json_decode(app(AgentFlo::class)->tools($delegation)->call($name, $args), true, 512, JSON_THROW_ON_ERROR);
}
