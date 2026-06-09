<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Rnkr69\LaraChatbot\Llm\SystemPromptBuilder;

beforeEach(function () {
    // Cada test parte de una vista base mínima y configurable. La vista
    // real publishable (resources/views/system_prompt.blade.php) se
    // ejercita en otro test por separado.
    View::addLocation(sys_get_temp_dir());
});

it('renders the published base view by default', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('You are a helpful assistant')
        ->and($built)
        ->toContain('Respond clearly and concisely');
});

it('does not include addendum when addendum_view is null', function () {
    config()->set('chatbot.system_prompt.addendum_view', null);

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->not->toContain('Host-specific guidance')
        ->and($built)
        ->not->toContain('Domain rules');
});

it('includes the addendum content when addendum_view is configured', function () {
    // Usamos la vista example que ya provee el paquete como referencia.
    $tmpView = sys_get_temp_dir() . '/chatbot_addendum_test.blade.php';
    file_put_contents(
        $tmpView,
        '## Domain rules' . "\n" . '- Use European date format dd/mm/yyyy.'
    );

    View::addNamespace('chatbot_test', sys_get_temp_dir());
    config()->set('chatbot.system_prompt.addendum_view', 'chatbot_test::chatbot_addendum_test');

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('Host-specific guidance')
        ->and($built)
        ->toContain('Use European date format dd/mm/yyyy.');

    @unlink($tmpView);
});

it('appends the locale instruction at the end when locale is provided', function () {
    $built = app(SystemPromptBuilder::class)->build(['locale' => 'es']);

    expect($built)
        ->toContain('Always respond in Spanish unless the user explicitly requests another language.');
});

it('translates the locale instruction for known locales', function (string $locale, string $expectedName) {
    $built = app(SystemPromptBuilder::class)->build(['locale' => $locale]);

    expect($built)->toContain("Always respond in {$expectedName} unless");
})->with([
    ['en', 'English'],
    ['es', 'Spanish'],
    ['ca', 'Catalan'],
    ['pt', 'Portuguese'],
    ['fr', 'French'],
    ['it', 'Italian'],
    ['de', 'German'],
    ['es-ES', 'Spanish'], // BCP47 → primer segmento
    ['en-US', 'English'],
]);

it('falls back to the raw locale string when locale is unknown', function () {
    $built = app(SystemPromptBuilder::class)->build(['locale' => 'xx']);

    expect($built)->toContain('Always respond in xx unless');
});

it('omits the locale instruction when locale is null or empty', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)->not->toContain('Always respond in');

    $built = app(SystemPromptBuilder::class)->build(['locale' => null]);

    expect($built)->not->toContain('Always respond in');

    $built = app(SystemPromptBuilder::class)->build(['locale' => '']);

    expect($built)->not->toContain('Always respond in');
});

it('renders the page_context section programmatically with the canonical "## Current page" header', function () {
    $built = app(SystemPromptBuilder::class)->build([
        'pageContext' => ['route' => 'orders.index', 'order_id' => 42],
    ]);

    // Header is the new canonical one (E14 D-paragraph): "## Current page".
    // The publishable view no longer renders the section — the builder does.
    expect($built)
        ->toContain('## Current page')
        ->and($built)->toContain('"route": "orders.index"')
        ->and($built)->toContain('"order_id": 42');
});

it('omits the page_context section when pageContext is empty', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)->not->toContain('## Current page');
});

it('changes the system prompt when page_context changes between builds (E14 DoD)', function () {
    $first = app(SystemPromptBuilder::class)->build([
        'pageContext' => ['route' => 'orders.index'],
    ]);

    $second = app(SystemPromptBuilder::class)->build([
        'pageContext' => ['route' => 'invoices.show', 'id' => 999],
    ]);

    expect($first)->not->toEqual($second);
    expect($first)->toContain('orders.index')->and($first)->not->toContain('invoices.show');
    expect($second)->toContain('invoices.show')->and($second)->toContain('999');
});

it('emits the canonical "## Current page" section even when the host overrides the publishable view', function () {
    // Simulamos un override total de la vista base por el host: el host la
    // sobrescribe a algo que NO contiene la sección de page context. El
    // contrato del paquete debe mantenerla porque vive en el builder.
    $tmpView = sys_get_temp_dir() . '/chatbot_overridden_base.blade.php';
    file_put_contents($tmpView, 'CUSTOM HOST PROMPT — without any page section.');

    \Illuminate\Support\Facades\View::addNamespace('chatbot_test', sys_get_temp_dir());
    config()->set('chatbot.system_prompt.view', 'chatbot_test::chatbot_overridden_base');

    $built = app(SystemPromptBuilder::class)->build([
        'pageContext' => ['route' => 'reports.index'],
    ]);

    expect($built)
        ->toContain('CUSTOM HOST PROMPT')
        ->and($built)->toContain('## Current page')
        ->and($built)->toContain('reports.index');

    @unlink($tmpView);
});

it('falls back to a hard-coded prompt when the configured view does not exist', function () {
    config()->set('chatbot.system_prompt.view', 'chatbot::nonexistent');

    $built = app(SystemPromptBuilder::class)->build(['locale' => 'es']);

    // Fallback string presente (sin Blade), y locale aún apendeado por el builder.
    expect($built)
        ->toContain('You are a helpful assistant integrated into a Laravel application.')
        ->and($built)
        ->toContain('Always respond in Spanish');
});

/*
|--------------------------------------------------------------------------
| E16 — Sección "## Pending actions".
|--------------------------------------------------------------------------
|
| El builder lee los pending actions de la conversación pasada por contexto
| y los lista para que el LLM "sepa" qué quedó pendiente / fue rechazado /
| expiró en turnos anteriores. Sólo se incluyen `pending|rejected|expired`;
| `confirmed` y `executed` se omiten (positivos no necesitan re-mención).
*/

// ===== v1.1.1 (findings #12.a, #14.g) =====

it('includes the decision strategy section by default (v1.1.1, finding #12.a)', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('## Page context — decision strategy')
        ->toContain('Listings');
});

it('omits the decision strategy when chatbot.system_prompt.decision_strategy=false', function () {
    config()->set('chatbot.system_prompt.decision_strategy', false);

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)->not->toContain('## Page context — decision strategy');
});

it('uses a custom view when decision_strategy is a view name', function () {
    $tmpView = sys_get_temp_dir() . '/chatbot_decision_test.blade.php';
    file_put_contents($tmpView, '## Custom decision rules' . "\n" . '- Only filter the grid.');

    View::addNamespace('chatbot_test', sys_get_temp_dir());
    config()->set('chatbot.system_prompt.decision_strategy', 'chatbot_test::chatbot_decision_test');

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('## Custom decision rules')
        ->and($built)->not->toContain('## Page context — decision strategy');

    @unlink($tmpView);
});

it('buildSplit() returns cacheable + dynamic prompt parts (v1.1.1, finding #14.g)', function () {
    $split = app(SystemPromptBuilder::class)->buildSplit([
        'pageContext' => ['crud' => ['entity' => 'Mission']],
    ]);

    expect($split)->toHaveKeys(['cacheable', 'dynamic']);
    expect($split['cacheable'])
        ->toContain('You are a helpful assistant')
        ->toContain('## Page context — decision strategy');
    expect($split['dynamic'])
        ->toContain('## Current page')
        ->toContain('Mission');
    // The two parts are disjoint (no overlap).
    expect($split['cacheable'])->not->toContain('## Current page');
    expect($split['dynamic'])->not->toContain('## Page context — decision strategy');
});

// ===== v1.1.3 (finding #23) — Current date/time anchor =====

it('includes the canonical "## Current date/time" section in build() (v1.1.3, finding #23)', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('## Current date/time')
        ->toContain(now()->format('Y'))
        ->toContain('derive them from this timestamp');
});

it('emits "## Current date/time" in the dynamic split — never cacheable (v1.1.3, finding #23)', function () {
    $split = app(SystemPromptBuilder::class)->buildSplit([]);

    expect($split['dynamic'])->toContain('## Current date/time');
    expect($split['cacheable'])->not->toContain('## Current date/time');
});

it('warns about Backpack filter lists in the decision strategy (v1.1.3, finding #16 sub-fix)', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('Backpack default list views do NOT expose')
        ->toContain('navigate({url:');
});

it('teaches that fill_form accepts partial input (v1.1.4, finding #27)', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('`fill_form` accepts partial input')
        ->toContain('Do NOT poll the user `required`-by-`required`')
        ->toContain('create_*` / `update_*` tools');
});

it('warns against hallucinating rows when the filter is unavailable (v1.1.4, finding #28)', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('Filter NOT available in the grid')
        ->toContain('Never invent, paraphrase or re-label rows')
        ->toContain('labelled as a fallback');
});

it('omits the "## Pending actions" section when there is no conversation in context', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)->not->toContain('## Pending actions');
});

it('omits the section when the conversation has no relevant pending actions', function () {
    $this->artisan('migrate')->run();

    $user = new \Rnkr69\LaraChatbot\Tests\Stubs\TestUser(['id' => 1, 'name' => 'U']);
    $user->setRawAttributes(['id' => 1, 'name' => 'U'], sync: true);

    $conversation = \Rnkr69\LaraChatbot\Models\Conversation::create([
        'user_type' => \Rnkr69\LaraChatbot\Tests\Stubs\TestUser::class,
        'user_id'   => 1,
        'title'     => null,
        'metadata'  => null,
    ]);

    $built = app(SystemPromptBuilder::class)->build(['conversation' => $conversation]);

    expect($built)->not->toContain('## Pending actions');
});

it('renders pending/rejected/expired pending actions but skips confirmed/executed (E16 DoD)', function () {
    $this->artisan('migrate')->run();

    $user = new \Rnkr69\LaraChatbot\Tests\Stubs\TestUser(['id' => 1, 'name' => 'U']);
    $user->setRawAttributes(['id' => 1, 'name' => 'U'], sync: true);

    $conversation = \Rnkr69\LaraChatbot\Models\Conversation::create([
        'user_type' => \Rnkr69\LaraChatbot\Tests\Stubs\TestUser::class,
        'user_id'   => 1,
        'title'     => null,
        'metadata'  => null,
    ]);

    $base = [
        'conversation_id' => $conversation->id,
        'tool'            => 'demo',
        'args'            => ['x' => 1],
        'confirmation'    => \Rnkr69\LaraChatbot\Models\PendingActionConfirmation::Confirm,
        'expires_at'      => now()->addMinutes(5),
    ];

    \Rnkr69\LaraChatbot\Models\PendingAction::create($base + [
        'action_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000001',
        'status'    => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Pending,
    ]);
    \Rnkr69\LaraChatbot\Models\PendingAction::create($base + [
        'action_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000002',
        'status'    => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Rejected,
    ]);
    \Rnkr69\LaraChatbot\Models\PendingAction::create($base + [
        'action_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000003',
        'status'    => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Expired,
    ]);
    \Rnkr69\LaraChatbot\Models\PendingAction::create($base + [
        'action_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000004',
        'status'    => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Confirmed,
    ]);
    \Rnkr69\LaraChatbot\Models\PendingAction::create($base + [
        'action_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-000000000005',
        'status'    => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Executed,
    ]);

    $built = app(SystemPromptBuilder::class)->build(['conversation' => $conversation]);

    expect($built)
        ->toContain('## Pending actions')
        ->toContain('[PENDING]')
        ->toContain('[REJECTED]')
        ->toContain('[EXPIRED]')
        ->and($built)->not->toContain('[CONFIRMED]')
        ->and($built)->not->toContain('[EXECUTED]');

    expect($built)->toContain('aaaaaaaa-aaaa-aaaa-aaaa-000000000001')
        ->and($built)->toContain('aaaaaaaa-aaaa-aaaa-aaaa-000000000002')
        ->and($built)->toContain('aaaaaaaa-aaaa-aaaa-aaaa-000000000003')
        ->and($built)->not->toContain('aaaaaaaa-aaaa-aaaa-aaaa-000000000004')
        ->and($built)->not->toContain('aaaaaaaa-aaaa-aaaa-aaaa-000000000005');
});

it('lists Executed rows with result.ok=false as [FAILED] (v1.1.3, finding #16)', function () {
    $this->artisan('migrate')->run();

    $user = new \Rnkr69\LaraChatbot\Tests\Stubs\TestUser(['id' => 1, 'name' => 'U']);
    $user->setRawAttributes(['id' => 1, 'name' => 'U'], sync: true);

    $conversation = \Rnkr69\LaraChatbot\Models\Conversation::create([
        'user_type' => \Rnkr69\LaraChatbot\Tests\Stubs\TestUser::class,
        'user_id'   => 1,
        'title'     => null,
        'metadata'  => null,
    ]);

    // We exercise the SystemPromptBuilder filter logic for Executed+ok:false.
    // The `confirmation` enum on the legacy SQLite test schema only ships
    // `confirm`/`manual` — production hosts widen it to include `auto` via
    // the v1.1.3 base migration, but the SystemPromptBuilder only looks at
    // status+result, not at confirmation. Using `Confirm` here doesn't change
    // what's being tested.
    \Rnkr69\LaraChatbot\Models\PendingAction::create([
        'conversation_id' => $conversation->id,
        'action_id'       => 'failed-fillform-uuid-aaaaaaaaaaaaaaaa',
        'tool'            => 'fill_form',
        'args'            => ['form_id' => 'filtersForm'],
        'status'          => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Executed,
        'confirmation'    => \Rnkr69\LaraChatbot\Models\PendingActionConfirmation::Confirm,
        'result'          => [
            'ok'      => false,
            'error'   => 'no_form_matched',
            'message' => 'No form matched id "filtersForm".',
            'available_forms' => [],
        ],
        'expires_at'      => now()->addMinute(),
    ]);

    // ok:true row — must be excluded.
    \Rnkr69\LaraChatbot\Models\PendingAction::create([
        'conversation_id' => $conversation->id,
        'action_id'       => 'ok-navigate-uuid-bbbbbbbbbbbbbbbbbb',
        'tool'            => 'navigate',
        'args'            => ['url' => '/admin'],
        'status'          => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Executed,
        'confirmation'    => \Rnkr69\LaraChatbot\Models\PendingActionConfirmation::Confirm,
        'result'          => ['ok' => true],
        'expires_at'      => now()->addMinute(),
    ]);

    $built = app(SystemPromptBuilder::class)->build(['conversation' => $conversation]);

    expect($built)
        ->toContain('[FAILED]')
        ->toContain('failed-fillform-uuid-aaaaaaaaaaaaaaaa')
        ->toContain('"error":"no_form_matched"')
        ->and($built)->not->toContain('ok-navigate-uuid-bbbbbbbbbbbbbbbbbb');
});

it('truncates the pending-actions list to chatbot.limits.pending_actions_in_prompt entries', function () {
    $this->artisan('migrate')->run();
    config()->set('chatbot.limits.pending_actions_in_prompt', 2);

    $user = new \Rnkr69\LaraChatbot\Tests\Stubs\TestUser(['id' => 1, 'name' => 'U']);
    $user->setRawAttributes(['id' => 1, 'name' => 'U'], sync: true);

    $conversation = \Rnkr69\LaraChatbot\Models\Conversation::create([
        'user_type' => \Rnkr69\LaraChatbot\Tests\Stubs\TestUser::class,
        'user_id'   => 1,
        'title'     => null,
        'metadata'  => null,
    ]);

    foreach (range(1, 5) as $i) {
        \Rnkr69\LaraChatbot\Models\PendingAction::create([
            'conversation_id' => $conversation->id,
            'action_id'       => 'truncate-' . $i . str_repeat('-x', 12),
            'tool'            => 'demo',
            'args'            => ['n' => $i],
            'status'          => \Rnkr69\LaraChatbot\Models\PendingActionStatus::Pending,
            'confirmation'    => \Rnkr69\LaraChatbot\Models\PendingActionConfirmation::Confirm,
            'result'          => null,
            'expires_at'      => now()->addMinutes(5),
        ]);
    }

    $built = app(SystemPromptBuilder::class)->build(['conversation' => $conversation]);

    // Sólo 2 entradas (los más recientes por id desc — los #5 y #4).
    expect(substr_count($built, '[PENDING]'))->toBe(2);
    expect($built)->toContain('truncate-5')->and($built)->toContain('truncate-4')
        ->and($built)->not->toContain('truncate-3');
});

// ──────────────────────────────────────────────────────────────────────────
// v2.2 — Dashboard conversational tool hints
// ──────────────────────────────────────────────────────────────────────────

it('appends the v2.2 dashboard tools hints section to the default decision strategy', function () {
    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('### Personal Dashboard — conversational tools (v2.2)')
        ->toContain('`add_to_dashboard`')
        ->toContain('`edit_widget`')
        ->toContain('`delete_widget`')
        ->toContain('`edit_dashboard`')
        ->toContain('`delete_dashboard`')
        ->toContain('Confirm verbally');
});

it('omits a hint bullet when its per-tool enabled flag is false (v2.2 toggle)', function () {
    config()->set('chatbot.tools.delete_dashboard.enabled', false);

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)->toContain('### Personal Dashboard — conversational tools (v2.2)');
    expect($built)->toContain('`add_to_dashboard`');
    // The disabled tool's bullet is gone — the LLM should not be nudged
    // toward an invocation that the registry will refuse.
    expect($built)->not->toContain('"delete my dashboard"');
});

it('does not append the v2.2 hints when the host uses a custom decision_strategy view', function () {
    $tmpView = sys_get_temp_dir() . '/chatbot_decision_custom.blade.php';
    file_put_contents($tmpView, '## Custom host rules' . "\n" . '- Only the host knows.');
    View::addNamespace('chatbot_test', sys_get_temp_dir());
    config()->set('chatbot.system_prompt.decision_strategy', 'chatbot_test::chatbot_decision_custom');

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)
        ->toContain('## Custom host rules')
        ->and($built)->not->toContain('### Personal Dashboard — conversational tools (v2.2)');

    @unlink($tmpView);
});

it('omits the v2.2 hints when chatbot.system_prompt.decision_strategy=false', function () {
    config()->set('chatbot.system_prompt.decision_strategy', false);

    $built = app(SystemPromptBuilder::class)->build([]);

    expect($built)->not->toContain('### Personal Dashboard — conversational tools (v2.2)');
});
