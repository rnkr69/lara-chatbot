<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Sse\SseEvent;
use Rnkr69\LaraChatbot\Tests\Evals\EvalToolStub;
use Rnkr69\LaraChatbot\Tests\Evals\EvalTraceCollector;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;
use Prism\Prism\ValueObjects\Usage;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->artisan('migrate')->run();
});

/**
 * Eval harness runner. Iterates every YAML fixture under tests/Evals/fixtures/
 * and asserts the orchestrator emits the tool_calls the fixture expects, with
 * matching args, and never invokes the fixture's forbidden_tools.
 *
 * Two modes:
 *  - DEFAULT (fake): we stage Prism::fake() with the expected tool_calls, so
 *    we're really testing that ChatService::handle() processes them
 *    correctly (cascade, SSE event sequence, args forwarding). Fast,
 *    deterministic, runs in CI.
 *  - LIVE (CHATBOT_EVAL_LIVE=1 + provider API key in env): we let the real LLM
 *    choose tools based on descriptions. Slow, non-deterministic, costs
 *    tokens. Excluded from CI; run manually. Per-fixture trace dumped to
 *    tests/Evals/last-live-run.json (gitignored). Live model + provider can
 *    be overridden via CHATBOT_EVAL_LIVE_PROVIDER / CHATBOT_EVAL_LIVE_MODEL;
 *    defaults to chatbot.provider / chatbot.model from config.
 *
 * 8 fixtures shipped — enough to catch the 3 invariants that have bitten
 * us most: tool selection, args correctness, multi-tool sequence. Grow
 * the suite each time a bug ships that reveals a hole.
 */
function loadEvalFixtures(): array
{
    $dir = __DIR__ . '/fixtures';
    $files = glob($dir . '/*.yaml');
    sort($files);

    $cases = [];
    foreach ($files as $path) {
        $f = Yaml::parseFile($path);
        $f['_filename'] = basename($path);
        $cases[basename($path)] = [$f];
    }
    return $cases;
}

function isLiveMode(): bool
{
    return filter_var(getenv('CHATBOT_EVAL_LIVE'), FILTER_VALIDATE_BOOLEAN);
}

function buildFakeStepFromExpected(array $expectedCalls): TextResponseFake
{
    if (empty($expectedCalls)) {
        // No tool calls expected — fake a plain text response.
        return TextResponseFake::make()
            ->withText('respuesta del modelo')
            ->withFinishReason(FinishReason::Stop)
            ->withUsage(new Usage(promptTokens: 5, completionTokens: 10));
    }

    $toolCalls = [];
    $toolResults = [];
    foreach ($expectedCalls as $i => $expected) {
        $callId = sprintf('eval_call_%d', $i + 1);
        $args = $expected['args_subset'] ?? [];
        $toolCalls[] = new ToolCall(id: $callId, name: $expected['name'], arguments: $args);
        $toolResults[] = new PrismToolResult(
            toolCallId: $callId,
            toolName: $expected['name'],
            args: $args,
            result: '{"status":"ok"}',
        );
    }

    return TextResponseFake::make()
        ->withSteps(collect([
            TextStepFake::make()
                ->withText('')
                ->withToolCalls($toolCalls)
                ->withToolResults($toolResults)
                ->withFinishReason(FinishReason::ToolCalls)
                ->withMeta(new Meta('eval-step-1', 'fake')),
            TextStepFake::make()
                ->withText('listo')
                ->withFinishReason(FinishReason::Stop)
                ->withMeta(new Meta('eval-step-2', 'fake')),
        ]))
        ->withFinishReason(FinishReason::Stop)
        ->withUsage(new Usage(promptTokens: 10, completionTokens: 5));
}

/**
 * Run the fixture's assertions against the captured emitted tool calls.
 * Returns null on pass, or a string describing the first failure (so live
 * mode can record it in the trace before re-throwing).
 *
 * @param  array<string, mixed>  $fx
 * @param  list<string>  $emittedToolNames
 * @param  array<string, list<array<string, mixed>>>  $emittedByName
 */
function runFixtureAssertions(array $fx, array $emittedToolNames, array $emittedByName): ?string
{
    foreach ($fx['expected']['forbidden_tools'] ?? [] as $forbidden) {
        if (in_array($forbidden, $emittedToolNames, true)) {
            return sprintf("forbidden tool '%s' was invoked", $forbidden);
        }
    }

    foreach ($fx['expected']['tool_calls'] ?? [] as $expected) {
        $expectedName = $expected['name'];
        if (! in_array($expectedName, $emittedToolNames, true)) {
            return sprintf(
                "expected tool '%s' was not invoked. Got: [%s]",
                $expectedName,
                implode(', ', $emittedToolNames),
            );
        }
        $argsSubset = $expected['args_subset'] ?? [];
        if (! empty($argsSubset)) {
            $invocations = $emittedByName[$expectedName] ?? [];
            $someMatch = false;
            foreach ($invocations as $actualArgs) {
                $matches = true;
                foreach ($argsSubset as $k => $v) {
                    if (! array_key_exists($k, $actualArgs) || $actualArgs[$k] != $v) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) { $someMatch = true; break; }
            }
            if (! $someMatch) {
                return sprintf(
                    "no invocation of '%s' matched args_subset %s. Got: %s",
                    $expectedName,
                    json_encode($argsSubset, JSON_UNESCAPED_SLASHES),
                    json_encode($invocations, JSON_UNESCAPED_SLASHES),
                );
            }
        }
    }

    if (! empty($fx['expected']['match_sequence'])) {
        $expectedSeq = array_column($fx['expected']['tool_calls'], 'name');
        $actualSeq = array_values(array_filter($emittedToolNames, fn ($n) => in_array($n, $expectedSeq, true)));
        if ($actualSeq !== $expectedSeq) {
            return sprintf(
                'tool-call sequence mismatch. expected=%s actual=%s',
                json_encode($expectedSeq, JSON_UNESCAPED_SLASHES),
                json_encode($actualSeq, JSON_UNESCAPED_SLASHES),
            );
        }
    }

    return null;
}

it('passes eval fixture', function (array $fx) {
    $live = isLiveMode();
    $fixtureName = $fx['_filename'] ?? 'unknown';

    // Stage tool catalogue from fixture.
    $registry = app(ToolRegistry::class)->clear();
    foreach ($fx['tools_available'] as $toolDef) {
        $stub = new EvalToolStub(
            toolName: $toolDef['name'],
            toolDescription: $toolDef['description'],
            parameters: $toolDef['parameters'] ?? ['type' => 'object', 'properties' => []],
        );
        $registry->register($stub);
    }

    // Resolve provider/model + stage either Prism::fake (default) or let the
    // real LLM answer (live mode).
    $provider = getenv('CHATBOT_EVAL_LIVE_PROVIDER') ?: (string) config('chatbot.provider');
    $model    = getenv('CHATBOT_EVAL_LIVE_MODEL') ?: (string) config('chatbot.model');

    if ($live) {
        config()->set('chatbot.provider', $provider);
        config()->set('chatbot.model', $model);
        // Don't stage Prism::fake — let the real provider answer.
    } else {
        Prism::fake([buildFakeStepFromExpected($fx['expected']['tool_calls'] ?? [])]);
    }

    // Build conversation and invoke.
    $user = new TestUser(['id' => 1, 'name' => 'EvalRunner']);
    $user->setRawAttributes(['id' => 1, 'name' => 'EvalRunner'], sync: true);
    $conversation = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => 1,
        'title'     => null,
        'metadata'  => null,
    ]);
    $conversation->setRelation('user', $user);

    $started = microtime(true);
    $events = [];
    $runError = null;
    try {
        foreach (app(ChatService::class)->handle($conversation, $fx['user_message'], $fx['page_context'] ?? []) as $event) {
            $events[] = $event;
        }
    } catch (Throwable $e) {
        $runError = $e;
    }
    $elapsedMs = (int) round((microtime(true) - $started) * 1000);

    // Extract the tool_calls + frontend_actions the orchestrator emitted.
    $emittedToolNames = array_values(array_map(
        fn (SseEvent $e) => $e->data['tool'] ?? ($e->data['name'] ?? null),
        array_filter($events, fn (SseEvent $e) => in_array($e->event, ['tool_call', 'frontend_action'], true)),
    ));
    /** @var array<string, list<array<string, mixed>>> $emittedByName */
    $emittedByName = [];
    foreach ($events as $e) {
        if (! in_array($e->event, ['tool_call', 'frontend_action'], true)) continue;
        $name = $e->data['tool'] ?? ($e->data['name'] ?? null);
        $emittedByName[$name][] = $e->data['args'] ?? ($e->data['arguments'] ?? []);
    }

    // Assistant text (for trace + future text_contains_any assertion).
    $assistantText = '';
    foreach ($events as $e) {
        if ($e->event === 'text') {
            $assistantText .= (string) ($e->data['delta'] ?? '');
        }
    }

    // Tokens + usage (when the LLM provided them; fake mode always does).
    $done = end($events) ?: null;
    $usage = ($done instanceof SseEvent && $done->event === 'done') ? ($done->data['usage'] ?? []) : [];

    // Run assertions and capture the failure reason for the trace.
    $failureReason = $runError !== null
        ? sprintf('handle() threw: %s', $runError->getMessage())
        : runFixtureAssertions($fx, $emittedToolNames, $emittedByName);
    $passed = ($failureReason === null);

    if ($live) {
        EvalTraceCollector::record([
            'fixture'    => $fixtureName,
            'name'       => $fx['name'] ?? $fixtureName,
            'pass'       => $passed,
            'reason'     => $failureReason,
            'error'      => $runError !== null ? get_class($runError) : null,
            'provider'   => $provider,
            'model'      => $model,
            'latency_ms' => $elapsedMs,
            'tokens'     => $usage,
            'expected'   => $fx['expected'] ?? [],
            'actual'     => [
                'tool_calls'       => $emittedToolNames,
                'tool_calls_args'  => $emittedByName,
                'assistant_text'   => $assistantText,
            ],
        ]);
    }

    if ($runError !== null) {
        throw $runError;
    }
    // Use `expect()` so Pest counts this as a real assertion (not "risky").
    // When `runFixtureAssertions` returns null, every check passed. When it
    // returns a string, the string is the failure reason — surfaced directly
    // by Pest's diff so the test output reads like an assertion failure.
    expect($failureReason)->toBeNull();
})->with(loadEvalFixtures());
