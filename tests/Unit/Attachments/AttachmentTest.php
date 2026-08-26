<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Attachments\Attachment;

function makeAttachment(array $overrides = []): Attachment
{
    return new Attachment(
        id: $overrides['id'] ?? 'uuid-1',
        name: $overrides['name'] ?? 'Oferta PV25.pdf',
        mime: $overrides['mime'] ?? 'application/pdf',
        size: $overrides['size'] ?? 1234,
        disk: $overrides['disk'] ?? 'local',
        path: $overrides['path'] ?? 'chatbot/attachments/7/uuid-1.pdf',
        text: $overrides['text'] ?? null,
        extracted: $overrides['extracted'] ?? false,
    );
}

it('exposes a lowercased extension derived from the name', function () {
    expect(makeAttachment(['name' => 'Report.PDF'])->extension())->toBe('pdf')
        ->and(makeAttachment(['name' => 'sheet.XLSX'])->extension())->toBe('xlsx')
        ->and(makeAttachment(['name' => 'noext'])->extension())->toBe('');
});

it('withText() sets text and flips the extracted flag', function () {
    $a = makeAttachment();
    expect($a->text)->toBeNull()->and($a->extracted)->toBeFalse();

    $withText = $a->withText('hola');
    expect($withText->text)->toBe('hola')
        ->and($withText->extracted)->toBeTrue()
        // immutability: original untouched
        ->and($a->text)->toBeNull();

    // null text means extraction did not produce anything → not extracted.
    $withNull = $a->withText(null);
    expect($withNull->text)->toBeNull()->and($withNull->extracted)->toBeFalse();
});

it('round-trips through toBlock()/fromBlock()', function () {
    $a = makeAttachment(['text' => 'contenido', 'extracted' => true]);
    $block = $a->toBlock();

    expect($block['type'])->toBe('attachment')
        ->and($block['name'])->toBe('Oferta PV25.pdf')
        ->and($block['text'])->toBe('contenido');

    $back = Attachment::fromBlock($block);
    expect($back)->not->toBeNull()
        ->and($back->id)->toBe($a->id)
        ->and($back->disk)->toBe($a->disk)
        ->and($back->path)->toBe($a->path)
        ->and($back->text)->toBe('contenido')
        ->and($back->extracted)->toBeTrue();
});

it('fromBlock() returns null for a non-attachment or malformed block', function () {
    expect(Attachment::fromBlock(['type' => 'text', 'text' => 'x']))->toBeNull()
        ->and(Attachment::fromBlock(['type' => 'attachment']))->toBeNull() // missing id/name/disk/path
        ->and(Attachment::fromBlock(['type' => 'attachment', 'id' => 'a', 'name' => 'n', 'disk' => 'local']))->toBeNull();
});
