<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * Invocation context that `BackendTool::handle()` receives. Immutable.
 *
 * - `user`         — the host's authenticated user. Tools use it to
 *                    resolve scopes and filter queries.
 * - `pageContext`  — sanitized payload from the `Page Context API` (E14). Includes
 *                    the current `route`, `entity`, filters and selection.
 *                    Free-form dictionary, already truncated by the sanitizer.
 * - `conversation` — active conversation if one exists (E08 provides it). May
 *                    be `null` in sandboxes (`chatbot:test-connection`,
 *                    isolated tests). Tools should not depend on its
 *                    presence except to write an audit log or read
 *                    `metadata`.
 * - `locale`       — the caller's effective locale (`User->locale` →
 *                    `app()->getLocale()` → 'en' as fallback). Useful for
 *                    formatting dates/numbers in the response.
 *
 * Intended to be built in E08 (`ChatService`) for each tool call and
 * passed to the `BaseBackendTool::execute()` it decorates.
 */
final class ToolContext
{
    /**
     * @param  array<string, mixed>  $pageContext
     */
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $pageContext = [],
        public readonly ?Conversation $conversation = null,
        public readonly ?string $locale = null,
    ) {}
}
