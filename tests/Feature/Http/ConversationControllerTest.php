<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Cache::flush();
});

/**
 * Crea un TestUser sin tabla real (mismo helper que ChatControllerStreamTest
 * y ChatServiceTest). El `id` se sincroniza vía `setRawAttributes` para que
 * `getKey()` y los morph relations no requieran persistencia.
 */
function makeOwnedConversationUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => "User-{$id}"]);
    $user->setRawAttributes(['id' => $id, 'name' => "User-{$id}"], sync: true);

    return $user;
}

/**
 * Atajo para crear una conversación del usuario dado, con título opcional
 * y `updated_at` controlado para los tests de orden.
 */
function makeOwnedConversation(TestUser $user, ?string $title = null, ?string $updatedAt = null): Conversation
{
    $conv = new Conversation();
    $conv->user_type = TestUser::class;
    $conv->user_id   = $user->getKey();
    $conv->title     = $title;
    $conv->save();

    if ($updatedAt !== null) {
        $conv->forceFill(['updated_at' => $updatedAt])->save();
    }

    return $conv->fresh();
}

// ──────────────────────────────────────────────────────────────────────────
// index
// ──────────────────────────────────────────────────────────────────────────

it('returns a paginated list of the authenticated user conversations only', function () {
    $self    = makeOwnedConversationUser(1);
    $foreign = makeOwnedConversationUser(99);

    makeOwnedConversation($self, 'Mine A');
    makeOwnedConversation($self, 'Mine B');
    makeOwnedConversation($foreign, 'Theirs');

    $response = $this->actingAs($self, 'web')->getJson('/chatbot/conversations');

    $response->assertOk();

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toHaveCount(2)
        ->and($titles)->toContain('Mine A')
        ->and($titles)->toContain('Mine B')
        ->and($titles)->not->toContain('Theirs');
});

it('returns an empty list when the user has no conversations', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')->getJson('/chatbot/conversations');

    $response->assertOk()->assertJsonPath('data', []);
});

it('paginates conversations using the configured default per_page', function () {
    config()->set('chatbot.limits.conversations_per_page.default', 3);

    $user = makeOwnedConversationUser();
    foreach (range(1, 7) as $i) {
        makeOwnedConversation($user, "Title {$i}");
    }

    $response = $this->actingAs($user, 'web')->getJson('/chatbot/conversations');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3)
        ->and((int) $response->json('meta.total'))->toBe(7);
});

it('honors ?per_page= when within the configured maximum', function () {
    config()->set('chatbot.limits.conversations_per_page.max', 50);

    $user = makeOwnedConversationUser();
    foreach (range(1, 12) as $i) {
        makeOwnedConversation($user, "T{$i}");
    }

    $response = $this->actingAs($user, 'web')->getJson('/chatbot/conversations?per_page=5');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(5);
});

it('rejects ?per_page= above the configured maximum with 422', function () {
    config()->set('chatbot.limits.conversations_per_page.max', 100);

    $user = makeOwnedConversationUser();
    makeOwnedConversation($user);

    $response = $this->actingAs($user, 'web')->getJson('/chatbot/conversations?per_page=999');

    $response->assertStatus(422)->assertJsonValidationErrors(['per_page']);
});

it('filters conversations by ?q= on the title (LIKE)', function () {
    $user = makeOwnedConversationUser();
    makeOwnedConversation($user, 'Quarterly invoices review');
    makeOwnedConversation($user, 'Onboarding plan');
    makeOwnedConversation($user, 'Invoices follow-up');
    makeOwnedConversation($user, null); // no title — debe quedar fuera del LIKE

    $response = $this->actingAs($user, 'web')
        ->getJson('/chatbot/conversations?q=invoice');

    $response->assertOk();
    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toHaveCount(2)
        ->and($titles)->toContain('Quarterly invoices review')
        ->and($titles)->toContain('Invoices follow-up');
});

it('orders conversations by updated_at desc (most recent first)', function () {
    $user = makeOwnedConversationUser();
    makeOwnedConversation($user, 'oldest', '2024-01-01 00:00:00');
    makeOwnedConversation($user, 'middle', '2024-06-01 00:00:00');
    makeOwnedConversation($user, 'newest', '2025-01-01 00:00:00');

    $response = $this->actingAs($user, 'web')->getJson('/chatbot/conversations');

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toBe(['newest', 'middle', 'oldest']);
});

it('excludes soft-deleted conversations from the index', function () {
    $user = makeOwnedConversationUser();
    $live = makeOwnedConversation($user, 'Live');
    $gone = makeOwnedConversation($user, 'Gone');
    $gone->delete();

    $response = $this->actingAs($user, 'web')->getJson('/chatbot/conversations');

    $titles = collect($response->json('data'))->pluck('title')->all();
    expect($titles)->toBe(['Live']);
});

it('rejects ?q= longer than 200 chars with 422', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')
        ->getJson('/chatbot/conversations?q=' . str_repeat('a', 201));

    $response->assertStatus(422)->assertJsonValidationErrors(['q']);
});

it('rejects unauthenticated requests on index via the auth middleware', function () {
    $response = $this->getJson('/chatbot/conversations');

    expect($response->status())->toBeIn([401, 302, 419, 403]);
});

// ──────────────────────────────────────────────────────────────────────────
// store
// ──────────────────────────────────────────────────────────────────────────

it('creates a new conversation associated with the authenticated user', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/conversations', [
        'title'    => 'Brand new chat',
        'metadata' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
    ]);

    $response->assertStatus(201);
    expect($response->json('data.title'))->toBe('Brand new chat')
        ->and($response->json('data.metadata.provider'))->toBe('anthropic');

    $conv = Conversation::query()->firstOrFail();
    expect($conv->user_type)->toBe(TestUser::class)
        ->and($conv->user_id)->toBe($user->getKey())
        ->and($conv->title)->toBe('Brand new chat')
        ->and($conv->metadata)->toBe(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
});

it('allows storing a conversation with no title and no metadata', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/conversations', []);

    $response->assertStatus(201);
    $conv = Conversation::query()->firstOrFail();
    expect($conv->title)->toBeNull()->and($conv->metadata)->toBeNull();
});

it('returns 422 when title exceeds 200 characters', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/conversations', [
        'title' => str_repeat('a', 201),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['title']);
});

it('returns 422 when metadata is not an array', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/conversations', [
        'metadata' => 'plain string',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['metadata']);
});

it('rejects unauthenticated requests on store via the auth middleware', function () {
    $response = $this->postJson('/chatbot/conversations', ['title' => 'x']);

    expect($response->status())->toBeIn([401, 302, 419, 403]);
});

// ──────────────────────────────────────────────────────────────────────────
// show
// ──────────────────────────────────────────────────────────────────────────

it('returns the conversation with messages cursor-paginated, last first', function () {
    $user = makeOwnedConversationUser();
    $conv = makeOwnedConversation($user, 'With history');

    foreach (range(1, 5) as $i) {
        Message::create([
            'conversation_id' => $conv->id,
            'role'            => MessageRole::User,
            'content'         => [['type' => 'text', 'text' => "msg-{$i}"]],
        ]);
    }

    $response = $this->actingAs($user, 'web')
        ->getJson('/chatbot/conversations/' . $conv->id . '?per_page=3');

    $response->assertOk();
    expect($response->json('data.id'))->toBe($conv->id)
        ->and($response->json('data.title'))->toBe('With history');

    $messages = $response->json('messages.data');
    expect($messages)->toHaveCount(3);

    // Último primero: el primer item es el msg con el id más alto.
    $texts = array_map(fn (array $m) => $m['content'][0]['text'], $messages);
    expect($texts[0])->toBe('msg-5')
        ->and($texts[1])->toBe('msg-4')
        ->and($texts[2])->toBe('msg-3');

    // El cursor de paginación está presente para el siguiente page. Vive
    // bajo `meta.next_cursor` por la envoltura que añade `ResourceCollection
    // ->response()` cuando wrapea un cursor paginator.
    expect($response->json('messages.meta.next_cursor'))->not->toBeNull()
        ->and($response->json('messages.meta.prev_cursor'))->toBeNull()
        ->and((int) $response->json('messages.meta.per_page'))->toBe(3);
});

it('returns the conversation with an empty messages list when there are none', function () {
    $user = makeOwnedConversationUser();
    $conv = makeOwnedConversation($user, 'Empty');

    $response = $this->actingAs($user, 'web')
        ->getJson('/chatbot/conversations/' . $conv->id);

    $response->assertOk();
    expect($response->json('messages.data'))->toBe([]);
});

it('returns 404 when showing a conversation owned by another user', function () {
    $self    = makeOwnedConversationUser(1);
    $foreign = makeOwnedConversationUser(99);
    $conv    = makeOwnedConversation($foreign, 'Theirs');

    $response = $this->actingAs($self, 'web')
        ->getJson('/chatbot/conversations/' . $conv->id);

    $response->assertStatus(404);
});

it('returns 404 when showing a soft-deleted conversation', function () {
    $user = makeOwnedConversationUser();
    $conv = makeOwnedConversation($user, 'Trashed');
    $conv->delete();

    $response = $this->actingAs($user, 'web')
        ->getJson('/chatbot/conversations/' . $conv->id);

    $response->assertStatus(404);
});

it('returns 404 when showing a non-existent conversation id', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')
        ->getJson('/chatbot/conversations/9999');

    $response->assertStatus(404);
});

it('rejects unauthenticated requests on show via the auth middleware', function () {
    $user = makeOwnedConversationUser();
    $conv = makeOwnedConversation($user);

    $response = $this->getJson('/chatbot/conversations/' . $conv->id);

    expect($response->status())->toBeIn([401, 302, 419, 403]);
});

// ──────────────────────────────────────────────────────────────────────────
// destroy
// ──────────────────────────────────────────────────────────────────────────

it('soft-deletes a conversation and responds 204 No Content', function () {
    $user = makeOwnedConversationUser();
    $conv = makeOwnedConversation($user, 'About to die');

    $response = $this->actingAs($user, 'web')
        ->deleteJson('/chatbot/conversations/' . $conv->id);

    $response->assertStatus(204);
    expect($response->getContent())->toBe('');

    // Soft-deleted: la fila sigue en BD pero `deleted_at` está poblado.
    $row = Conversation::withTrashed()->find($conv->id);
    expect($row)->not->toBeNull()
        ->and($row->deleted_at)->not->toBeNull();

    // El default scope (sin `withTrashed`) ya no la ve.
    expect(Conversation::query()->find($conv->id))->toBeNull();
});

it('returns 404 when destroying a conversation owned by another user (DoD policy)', function () {
    $self    = makeOwnedConversationUser(1);
    $foreign = makeOwnedConversationUser(99);
    $conv    = makeOwnedConversation($foreign, 'Not yours');

    $response = $this->actingAs($self, 'web')
        ->deleteJson('/chatbot/conversations/' . $conv->id);

    $response->assertStatus(404);

    // No se ha tocado la fila ajena.
    expect(Conversation::query()->find($conv->id))->not->toBeNull();
});

it('returns 404 when destroying a non-existent conversation id', function () {
    $user = makeOwnedConversationUser();

    $response = $this->actingAs($user, 'web')
        ->deleteJson('/chatbot/conversations/9999');

    $response->assertStatus(404);
});

it('rejects unauthenticated requests on destroy via the auth middleware', function () {
    $user = makeOwnedConversationUser();
    $conv = makeOwnedConversation($user);

    $response = $this->deleteJson('/chatbot/conversations/' . $conv->id);

    expect($response->status())->toBeIn([401, 302, 419, 403]);
});
