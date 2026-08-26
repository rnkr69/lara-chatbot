<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Attachments;

use Rnkr69\LaraChatbot\Attachments\Contracts\AttachmentProcessor;

/**
 * Default, dependency-free processor. It extracts text ONLY from text-like
 * files (`text/*`, plus common code/data extensions and CSV). Everything else
 * — PDF, spreadsheets, Word, e-mail — returns null so the host is nudged to
 * bind a richer processor via `config('chatbot.attachments.processor')`.
 *
 * Kept deliberately tiny: the package must not pull heavy parsing
 * dependencies. The reference rich processor for Sofia4Request lives in the
 * host app and uses pure-PHP libraries (pdfparser, phpspreadsheet, phpword,
 * mail-mime-parser).
 */
class PlainTextProcessor implements AttachmentProcessor
{
    /** Extensions we are willing to read as UTF-8 text even without a text/* mime. */
    protected const TEXT_EXTENSIONS = [
        'txt', 'csv', 'tsv', 'md', 'markdown', 'log',
        'json', 'xml', 'yml', 'yaml', 'ini', 'html', 'htm',
    ];

    public function extract(Attachment $attachment): ?string
    {
        if (! $this->looksLikeText($attachment)) {
            return null;
        }

        $bytes = $attachment->contents();
        if ($bytes === null || $bytes === '') {
            return null;
        }

        // Normalize to UTF-8 best-effort; drop a BOM if present.
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $converted = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            if (is_string($converted)) {
                $bytes = $converted;
            }
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;

        $text = trim($bytes);

        return $text === '' ? null : $text;
    }

    protected function looksLikeText(Attachment $attachment): bool
    {
        if (str_starts_with($attachment->mime, 'text/')) {
            return true;
        }

        return in_array($attachment->extension(), static::TEXT_EXTENSIONS, true);
    }
}
