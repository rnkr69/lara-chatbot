<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Attachments\Contracts;

use Rnkr69\LaraChatbot\Attachments\Attachment;

/**
 * Turns a stored {@see Attachment} into plain text for the LLM turn.
 *
 * The whole attachment pipeline is provider-agnostic: instead of shipping
 * binary documents to a multimodal model, the host extracts text server-side
 * and the package injects it as ordinary text. That keeps the chatbot working
 * against ANY LLM provider and lets each host decide how to read PDFs,
 * spreadsheets, e-mails, etc.
 *
 * The package ships a {@see \Rnkr69\LaraChatbot\Attachments\PlainTextProcessor}
 * default that only handles text-like files. Hosts bind their own richer
 * implementation via `config('chatbot.attachments.processor')`.
 */
interface AttachmentProcessor
{
    /**
     * Return the extracted plain text, or null when no text can be produced
     * (unsupported type, scanned/image-only PDF, corrupt file, …). Returning
     * null is not an error: the caller records the attachment as "not
     * extracted" and the LLM is told the document could not be read.
     *
     * Implementations MUST NOT throw for an unreadable file — return null. Any
     * thrown exception is caught upstream and treated as null, but returning
     * null keeps intent explicit.
     */
    public function extract(Attachment $attachment): ?string;
}
