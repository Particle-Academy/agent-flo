<?php

declare(strict_types=1);

namespace AgentFlo\Tests\Support;

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

/**
 * A hermetic Prism provider that EXECUTES the tools a run offers. No network,
 * no model, no cost. Each call to the provider pops the next turn; a turn is a
 * list of tool calls and a final text. A nested subagent run pops its own turn
 * while the parent's tool call is in flight, so a script reads in call order.
 *
 * `args` may be a closure over the results so far, which is how a script pins a
 * revision it only learns at run time.
 */
final class ScriptedProvider extends Provider
{
    /** @var list<TextRequest> */
    public array $requests = [];

    /** @var list<array{name:string,args:array,result:string}> */
    public array $calls = [];

    /** @param list<array{tools?:list<array{name:string,args:array|\Closure}>,text?:string}> $turns */
    public function __construct(public array $turns = []) {}

    public function text(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;
        $turn = array_shift($this->turns) ?? ['text' => 'Done.'];

        $offered = [];
        foreach ($request->tools() as $tool) {
            $offered[$tool->name()] = $tool;
        }

        $toolCalls = [];
        $toolResults = [];
        foreach ($turn['tools'] ?? [] as $i => $call) {
            $args = $call['args'] instanceof \Closure ? ($call['args'])($this->calls) : $call['args'];
            $tool = $offered[$call['name']] ?? throw new RuntimeException('Script called an unoffered tool: '.$call['name'].' (offered: '.implode(', ', array_keys($offered)).')');
            $output = $tool->handle(...$args);
            $result = is_string($output) ? $output : (string) json_encode($output);
            $this->calls[] = ['name' => $call['name'], 'args' => $args, 'result' => $result];
            $toolCalls[] = new ToolCall('call_'.count($this->calls), $call['name'], $args);
            $toolResults[] = new ToolResult('call_'.count($this->calls), $call['name'], $args, $result);
        }

        return new TextResponse(
            steps: new Collection,
            text: $turn['text'] ?? 'Done.',
            finishReason: FinishReason::Stop,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: new Usage(100, 20),
            meta: new Meta('scripted-'.count($this->requests), $request->model()),
            messages: new Collection,
        );
    }

    /** Every tool name the Nth request offered. */
    public function offered(int $nth): array
    {
        return array_map(static fn ($t) => $t->name(), $this->requests[$nth]->tools());
    }

    /** The decoded result of the Nth call to a tool. */
    public function result(string $tool, int $nth = 0): array
    {
        $matches = array_values(array_filter($this->calls, fn (array $c) => $c['name'] === $tool));

        return json_decode($matches[$nth]['result'] ?? 'null', true) ?? throw new RuntimeException("No call {$nth} to {$tool}.");
    }
}
