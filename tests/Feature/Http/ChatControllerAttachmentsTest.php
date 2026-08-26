<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Rnkr69\LaraChatbot\Models\Message;
use Rnkr69\LaraChatbot\Models\MessageRole;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

beforeEach(function () {
    $this->artisan('migrate')->run();
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Cache::flush();
    Storage::fake('local');
    config()->set('chatbot.attachments.enabled', true);
    config()->set('chatbot.attachments.disk', 'local');
});

function attachUser(int $id = 1): TestUser
{
    $user = new TestUser(['id' => $id, 'name' => "User-{$id}"]);
    $user->setRawAttributes(['id' => $id, 'name' => "User-{$id}"], sync: true);

    return $user;
}

it('accepts a multipart turn with a document, stores it and persists an attachment block with extracted text', function () {
    Prism::fake([
        TextResponseFake::make()->withText('recibido')->withFinishReason(FinishReason::Stop),
    ]);

    $user = attachUser();
    $file = UploadedFile::fake()->createWithContent('oferta.txt', "Proveedor: IMCO\nTotal: 47.046,91 EUR");

    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', [
        'message'     => 'Prepárame la solicitud con esta oferta',
        'attachments' => [$file],
    ]);

    $response->assertOk();
    $response->streamedContent();

    $userMsg = Message::query()->where('role', MessageRole::User->value)->firstOrFail();
    $blocks  = $userMsg->content;

    // text block + attachment block
    $attachmentBlock = collect($blocks)->firstWhere('type', 'attachment');
    expect($attachmentBlock)->not->toBeNull()
        ->and($attachmentBlock['name'])->toBe('oferta.txt')
        ->and($attachmentBlock['extracted'])->toBeTrue()
        ->and($attachmentBlock['text'])->toContain('Proveedor: IMCO')
        ->and($attachmentBlock['text'])->toContain('47.046,91');

    Storage::disk('local')->assertExists($attachmentBlock['path']);
});

it('allows an attachment-only turn (empty message) when a file is present', function () {
    Prism::fake([
        TextResponseFake::make()->withText('ok')->withFinishReason(FinishReason::Stop),
    ]);

    $user = attachUser();
    $file = UploadedFile::fake()->createWithContent('nota.txt', 'contenido');

    $response = $this->actingAs($user, 'web')->post('/chatbot/stream', [
        'message'     => '',
        'attachments' => [$file],
    ]);

    $response->assertOk();
    $response->streamedContent();

    expect(Message::query()->where('role', MessageRole::User->value)->count())->toBe(1);
});

it('rejects attachments when the feature is disabled', function () {
    config()->set('chatbot.attachments.enabled', false);

    $user = attachUser();
    $file = UploadedFile::fake()->createWithContent('nota.txt', 'x');

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/stream', [
        'message'     => 'hola',
        'attachments' => [$file],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['attachments']);
});

it('rejects a disallowed file extension', function () {
    config()->set('chatbot.attachments.allowed_extensions', ['pdf', 'txt']);

    $user = attachUser();
    $bad  = UploadedFile::fake()->create('archive.zip', 10, 'application/zip');

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/stream', [
        'message'     => 'hola',
        'attachments' => [$bad],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['attachments.0']);
});

it('rejects more files than max_files', function () {
    config()->set('chatbot.attachments.max_files', 1);

    $user = attachUser();
    $f1 = UploadedFile::fake()->createWithContent('a.txt', 'a');
    $f2 = UploadedFile::fake()->createWithContent('b.txt', 'b');

    $response = $this->actingAs($user, 'web')->postJson('/chatbot/stream', [
        'message'     => 'hola',
        'attachments' => [$f1, $f2],
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['attachments']);
});
