<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Rnkr69\LaraChatbot\Attachments\AttachmentStore;
use Rnkr69\LaraChatbot\Attachments\PlainTextProcessor;
use Rnkr69\LaraChatbot\Models\Conversation;
use Rnkr69\LaraChatbot\Tests\Stubs\TestUser;

beforeEach(function () {
    $this->artisan('migrate')->run();
    Storage::fake('local');
    config()->set('chatbot.attachments.enabled', true);
    config()->set('chatbot.attachments.disk', 'local');
    config()->set('chatbot.attachments.path', 'chatbot/attachments');
});

function makeAttachmentConversation(): Conversation
{
    $user = new TestUser(['id' => 7]);
    $user->setRawAttributes(['id' => 7], sync: true);

    return Conversation::create([
        'user_type' => TestUser::class,
        'user_id'   => $user->getKey(),
    ]);
}

it('stores uploaded files under <path>/<conversation_id>/ and returns Attachments', function () {
    $conversation = makeAttachmentConversation();
    $file = UploadedFile::fake()->createWithContent('oferta.txt', 'linea 1');

    $stored = app(AttachmentStore::class)->storeUploaded($conversation, [$file]);

    expect($stored)->toHaveCount(1);
    $att = $stored[0];

    expect($att->name)->toBe('oferta.txt')
        ->and($att->disk)->toBe('local')
        ->and($att->path)->toStartWith("chatbot/attachments/{$conversation->id}/")
        ->and($att->size)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($att->path);
});

it('returns an empty array and stores nothing when the feature is disabled', function () {
    config()->set('chatbot.attachments.enabled', false);
    $conversation = makeAttachmentConversation();
    $file = UploadedFile::fake()->createWithContent('x.txt', 'hi');

    $stored = app(AttachmentStore::class)->storeUploaded($conversation, [$file]);

    expect($stored)->toBe([]);
});

it('lets PlainTextProcessor extract text from a stored text file', function () {
    $conversation = makeAttachmentConversation();
    $file = UploadedFile::fake()->createWithContent('nota.txt', "Proveedor: IMCO\nImporte: 47.046,91");

    [$att] = app(AttachmentStore::class)->storeUploaded($conversation, [$file]);

    $text = (new PlainTextProcessor)->extract($att);

    expect($text)->toContain('Proveedor: IMCO')->toContain('47.046,91');
});

it('PlainTextProcessor returns null for a non-text type (e.g. a PDF)', function () {
    $conversation = makeAttachmentConversation();
    // A .pdf handled by the default processor is out of scope → null.
    $file = UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 binary-ish');

    [$att] = app(AttachmentStore::class)->storeUploaded($conversation, [$file]);

    expect((new PlainTextProcessor)->extract($att))->toBeNull();
});
