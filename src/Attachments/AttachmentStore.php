<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Persists uploaded chat files to the configured disk and turns each into an
 * {@see Attachment} value object. Validation (mimes, size, count) is the
 * FormRequest's job; this service assumes the files are already valid.
 *
 * Layout on disk: `<attachments.path>/<conversation_id>/<uuid>.<ext>`.
 */
class AttachmentStore
{
    /**
     * @param  array<int, UploadedFile|null>  $files
     * @return array<int, Attachment>
     */
    public function storeUploaded(Conversation $conversation, array $files): array
    {
        if (! (bool) config('chatbot.attachments.enabled', false) || $files === []) {
            return [];
        }

        $disk    = $this->disk();
        $baseDir = trim((string) config('chatbot.attachments.path', 'chatbot/attachments'), '/');
        $dir     = $baseDir . '/' . $conversation->id;

        $stored = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $id  = (string) Str::uuid();
            $ext = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'bin'));

            $path = $file->storeAs($dir, "{$id}.{$ext}", ['disk' => $disk]);
            if ($path === false || $path === '') {
                continue;
            }

            $stored[] = new Attachment(
                id: $id,
                name: $this->safeName($file->getClientOriginalName(), $ext),
                mime: $this->resolveMime($file, $disk, $path),
                size: $this->resolveSize($file, $disk, $path),
                disk: $disk,
                path: $path,
            );
        }

        return $stored;
    }

    protected function disk(): string
    {
        $disk = config('chatbot.attachments.disk');

        return is_string($disk) && $disk !== ''
            ? $disk
            : (string) config('filesystems.default', 'local');
    }

    protected function safeName(string $original, string $ext): string
    {
        $name = trim($original);
        if ($name === '') {
            $name = 'documento.' . $ext;
        }

        // Keep it a bare filename — strip any path the browser may have sent.
        return basename(str_replace('\\', '/', $name));
    }

    protected function resolveMime(UploadedFile $file, string $disk, string $path): string
    {
        $mime = $file->getClientMimeType();
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }

        try {
            $detected = Storage::disk($disk)->mimeType($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        } catch (\Throwable) {
            // fall through
        }

        return 'application/octet-stream';
    }

    protected function resolveSize(UploadedFile $file, string $disk, string $path): int
    {
        $size = $file->getSize();
        if (is_int($size) && $size > 0) {
            return $size;
        }

        try {
            return (int) Storage::disk($disk)->size($path);
        } catch (\Throwable) {
            return 0;
        }
    }
}
