<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Attachments;

use Illuminate\Support\Facades\Storage;

/**
 * Immutable value object for a chat attachment already persisted on a
 * filesystem disk. It carries the metadata needed to (a) render a chip in the
 * widget and (b) let an {@see Contracts\AttachmentProcessor} read the bytes and
 * extract plain text for the LLM turn.
 *
 * The extracted `text` is filled AFTER storage by the processor (see
 * {@see withText()}); a freshly stored attachment has `text = null` and
 * `extracted = false`.
 *
 * The object serializes to/from a `type: 'attachment'` block that lives inside
 * the user message's `content[]` (a plain JSON column — no schema change). This
 * keeps the whole feature provider-agnostic: nothing here depends on Prism or
 * on any LLM being able to read binary documents natively.
 */
final class Attachment
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $size,
        public readonly string $disk,
        public readonly string $path,
        public readonly ?string $text = null,
        public readonly bool $extracted = false,
    ) {}

    /**
     * Returns a copy with the extracted text set. `extracted` is true whenever
     * a non-null string was produced (even an empty string counts as "the
     * processor ran"); a null text means extraction failed or was skipped.
     */
    public function withText(?string $text): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            mime: $this->mime,
            size: $this->size,
            disk: $this->disk,
            path: $this->path,
            text: $text,
            extracted: $text !== null,
        );
    }

    /** Lowercased file extension derived from the original name (no dot). */
    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /** Raw bytes from the backing disk, or null if the file is unreadable. */
    public function contents(): ?string
    {
        try {
            $disk = Storage::disk($this->disk);

            return $disk->exists($this->path) ? $disk->get($this->path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Absolute local path, when the disk is a local driver that exposes one.
     * Returns null for remote disks (S3, etc.). Useful for processors backed by
     * libraries that only accept a filesystem path.
     */
    public function localPath(): ?string
    {
        try {
            $disk = Storage::disk($this->disk);
            $path = method_exists($disk, 'path') ? $disk->path($this->path) : null;

            return is_string($path) && is_file($path) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Canonical `content[]` block shape persisted in the message and re-read on
     * reload / history replay.
     *
     * @return array<string, mixed>
     */
    public function toBlock(): array
    {
        return [
            'type'      => 'attachment',
            'id'        => $this->id,
            'name'      => $this->name,
            'mime'      => $this->mime,
            'size'      => $this->size,
            'disk'      => $this->disk,
            'path'      => $this->path,
            'text'      => $this->text,
            'extracted' => $this->extracted,
        ];
    }

    /**
     * Rebuilds an Attachment from a persisted `content[]` block. Returns null
     * when the block is not a well-formed attachment block.
     *
     * @param  array<string, mixed>  $block
     */
    public static function fromBlock(array $block): ?self
    {
        if (($block['type'] ?? null) !== 'attachment') {
            return null;
        }

        $id   = $block['id']   ?? null;
        $name = $block['name'] ?? null;
        $disk = $block['disk'] ?? null;
        $path = $block['path'] ?? null;

        if (! is_string($id) || ! is_string($name) || ! is_string($disk) || ! is_string($path)) {
            return null;
        }

        $text = $block['text'] ?? null;

        return new self(
            id: $id,
            name: $name,
            mime: is_string($block['mime'] ?? null) ? $block['mime'] : 'application/octet-stream',
            size: is_int($block['size'] ?? null) ? $block['size'] : (int) ($block['size'] ?? 0),
            disk: $disk,
            path: $path,
            text: is_string($text) ? $text : null,
            extracted: (bool) ($block['extracted'] ?? false),
        );
    }
}
