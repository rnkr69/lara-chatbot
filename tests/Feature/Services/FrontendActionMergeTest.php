<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Rnkr69\LaraChatbot\Events\ToolInvoked;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Services\ChatService;
use Rnkr69\LaraChatbot\Sse\SseEvent;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Rnkr69\LaraChatbot\Tests\Stubs\Tools\DownloadManifestStub;
use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Testing\TextStepFake;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult as PrismToolResult;

/*
|--------------------------------------------------------------------------
| ChatService merges `ToolResult::data` into `frontend_action.args` (E11)
|--------------------------------------------------------------------------
|
| The test covers the full end-to-end flow of `DownloadFileTool`: the LLM
| calls the tool with a disk path, `handle()` signs the URL, and the widget
| receives `frontend_action` with `download_url`/`expires_at` merged into the
| args. The LLM sees a neutral "queued" `tool_result`.
*/

beforeEach(function () {
    $this->artisan('migrate')->run();
    config()->set('chatbot.tools.download_file.allowed_disks', ['attachments']);
    config()->set('chatbot.tools.download_file.max_expires_in', 3600);
    Storage::fake('attachments');
});

function makeDownloadConversation(int $userId = 1): Conversation
{
    $user = new TestUser(['id' => $userId, 'name' => 'Tester']);
    $user->setRawAttributes(['id' => $userId, 'name' => 'Tester'], sync: true);

    $c = Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $userId,
        'title'     => null,
        'metadata'  => null,
    ]);

    $c->setRelation('user', $user);

    return $c;
}

it('merges DownloadFileTool data (download_url + expires_at) into frontend_action.args', function () {
    Event::fake([ToolInvoked::class]);

    Storage::disk('attachments')->put('invoices/2026/123.pdf', 'fake');

    // Replace the auto-registered DownloadFileTool with a fresh instance to
    // ensure the test has a clean target.
    app(ToolRegistry::class)->clear()->register(new DownloadFileTool);

    $now = Carbon::create(2026, 5, 9, 12, 0, 0);
    Carbon::setTestNow($now);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_dl_1',
                            name: 'download_file',
                            arguments: [
                                'url_or_disk_path' => 'attachments::invoices/2026/123.pdf',
                                'filename'         => 'factura-123.pdf',
                                'expires_in'       => 600,
                            ],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_dl_1',
                            toolName: 'download_file',
                            args: ['url_or_disk_path' => 'attachments::invoices/2026/123.pdf'],
                            result: '{"status":"queued"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('Here is the invoice.')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $service = app(ChatService::class);

    $events = [];
    foreach ($service->handle(makeDownloadConversation(), 'dame la factura 123') as $event) {
        $events[] = $event;
    }

    Carbon::setTestNow();

    $feEvents = array_values(array_filter($events, fn (SseEvent $e) => $e->event === 'frontend_action'));

    expect($feEvents)->toHaveCount(1);

    $fe = $feEvents[0];

    expect($fe->data['tool'])->toBe('download_file')
        ->and($fe->data['confirmation'])->toBe('auto')
        ->and($fe->data['action_id'])->toBeString()
        ->and($fe->data['args'])->toHaveKey('url_or_disk_path', 'attachments::invoices/2026/123.pdf')
        ->and($fe->data['args'])->toHaveKey('filename', 'factura-123.pdf')
        ->and($fe->data['args'])->toHaveKey('expires_in', 600)
        ->and($fe->data['args'])->toHaveKey('download_url')
        ->and($fe->data['args']['download_url'])->toBeString()
        ->and($fe->data['args']['download_url'])->not->toBe('')
        ->and($fe->data['args'])->toHaveKey('expires_at', $now->copy()->addSeconds(600)->toIso8601String());

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e) => $e->tool->name() === 'download_file');
});

it('emits NO frontend_action when DownloadFileTool fails the cascade (disk not allowed)', function () {
    Event::fake([ToolInvoked::class]);

    config()->set('chatbot.tools.download_file.allowed_disks', []); // refuse all
    app(ToolRegistry::class)->clear()->register(new DownloadFileTool);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_dl_x',
                            name: 'download_file',
                            arguments: ['url_or_disk_path' => 'attachments::any.pdf'],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_dl_x',
                            toolName: 'download_file',
                            args: ['url_or_disk_path' => 'attachments::any.pdf'],
                            result: '{"status":"error"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('No pude generar la descarga.')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $service = app(ChatService::class);

    $events = [];
    foreach ($service->handle(makeDownloadConversation(), 'descarga') as $event) {
        $events[] = $event;
    }

    $kinds = array_map(fn (SseEvent $e) => $e->event, $events);

    // No `frontend_action`: the cascade failed (empty allowed_disks). Instead,
    // ChatService emits an informative `tool_result` with ok=false.
    expect(in_array('frontend_action', $kinds, true))->toBeFalse()
        ->and(in_array('tool_result', $kinds, true))->toBeTrue();

    // ToolInvoked fires regardless (gap E08 — audit listeners see the
    // rejection).
    Event::assertDispatched(ToolInvoked::class);
});

it('emits frontend_action with the canonical primitive name when a subclass overrides frontendPrimitiveName (#25)', function () {
    Event::fake([ToolInvoked::class]);

    Storage::disk('attachments')->put('manifests/op-1.pdf', 'fake');

    // The host registers a subclass named `download_manifest` (so the LLM
    // discovers it as a distinct tool with its own description) but declares
    // `download_file` as the widget's canonical primitive.
    app(ToolRegistry::class)->clear()->register(new DownloadManifestStub);

    Prism::fake([
        TextResponseFake::make()
            ->withSteps(collect([
                TextStepFake::make()
                    ->withText('')
                    ->withToolCalls([
                        new ToolCall(
                            id: 'call_manifest_1',
                            name: 'download_manifest',
                            arguments: [
                                'url_or_disk_path' => 'attachments::manifests/op-1.pdf',
                            ],
                        ),
                    ])
                    ->withToolResults([
                        new PrismToolResult(
                            toolCallId: 'call_manifest_1',
                            toolName: 'download_manifest',
                            args: ['url_or_disk_path' => 'attachments::manifests/op-1.pdf'],
                            result: '{"status":"queued"}',
                        ),
                    ])
                    ->withFinishReason(FinishReason::ToolCalls),
                TextStepFake::make()
                    ->withText('Here is the manifest.')
                    ->withFinishReason(FinishReason::Stop),
            ]))
            ->withFinishReason(FinishReason::Stop),
    ]);

    $service = app(ChatService::class);

    $events = [];
    foreach ($service->handle(makeDownloadConversation(), 'dame el manifest') as $event) {
        $events[] = $event;
    }

    $feEvents = array_values(array_filter($events, fn (SseEvent $e) => $e->event === 'frontend_action'));

    expect($feEvents)->toHaveCount(1)
        ->and($feEvents[0]->data['tool'])->toBe('download_file')
        ->and($feEvents[0]->data['args'])->toHaveKey('download_url');

    // ToolInvoked still sees the tool's real host-side `name()`.
    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $e) => $e->tool->name() === 'download_manifest');
});
