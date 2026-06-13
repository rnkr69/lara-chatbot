<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View as ViewFactory;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * E17 — `GET /{chatbot.route.prefix}` (default `/chatbot`).
 *
 * Returns the publishable `chatbot::page` view that mounts
 * `<chatbot-widget mode="page">` full-screen with a conversations
 * sidebar. The view resolves dynamically:
 *
 *   - `$layout` — name of the host layout (`chatbot.page.layout`). null if the
 *     host did not configure it or if the view does not exist (standalone fallback).
 *   - `$section` — name of the layout section into which to inject the
 *     content when `$layout !== null`. Default `content`.
 *   - `$assetUrl` — URL of the `chatbot-widget.js` bundle resolved via
 *     `asset(chatbot.widget.asset_path)`.
 *   - `$streamUrl` — URL of the chat SSE endpoint (`route('chatbot.stream')`).
 *   - `$conversationsUrl` — base URL of the conversations CRUD; the widget
 *     uses it for the sidebar (list, search, delete) and for `show` when
 *     it selects a conversation.
 *
 * The controller requires no additional permission beyond the group middleware;
 * per-user authorization is inherited by the internal calls
 * (`/chatbot/conversations`, `/chatbot/stream`).
 */
class PageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $layout = $this->resolveLayout();
        $section = (string) config('chatbot.page.section', 'content');
        if ($section === '') {
            $section = 'content';
        }

        $assetPath = (string) config('chatbot.widget.asset_path', 'vendor/chatbot/chatbot-widget.js');
        $assetUrl = asset($assetPath);

        // Two views: `chatbot::page` is fully standalone (no `@extends`);
        // `chatbot::page_layout` extends the host layout. Splitting them is
        // necessary because Blade compiles `@extends(...)` to a footer that
        // runs unconditionally — wrapping it in `@if(...)` would still try to
        // load a null layout and explode with "View [] not found".
        $viewName = $layout !== null ? 'chatbot::page_layout' : 'chatbot::page';

        return view($viewName, [
            'layout'                => $layout,
            'section'               => $section,
            'assetUrl'              => $assetUrl,
            'streamUrl'             => route('chatbot.stream'),
            'conversationsUrl'      => route('chatbot.conversations.index'),
            'theme'                 => (string) config('chatbot.widget.theme', 'auto'),
            'initialConversationId' => $this->resolveInitialConversation($request),
            'i18n'                  => $this->resolveI18n(),
            // v2.1.1 (#26) — "back to app" link for the standalone view, so it
            // is not a navigation-less island.
            'backUrl'               => $this->resolveBackUrl(),
        ]);
    }

    /**
     * v2.1.1 (#26) — URL of the "← back to app" link in the chat's
     * standalone view. It is only painted if it is a non-empty string; null
     * leaves it without a link. In `layout` mode the navigation is provided by the host.
     */
    protected function resolveBackUrl(): ?string
    {
        $backUrl = config('chatbot.page.back_url');

        return is_string($backUrl) && $backUrl !== '' ? $backUrl : null;
    }

    /**
     * v2.0 / E9 — PHP → JS bridge for the package's UI keys.
     *
     * Returns `__('chatbot::chatbot')` as an array so the blade can
     * `json_encode` it in `<chatbot-widget data-i18n="…">`. The widget bundle
     * reads this attribute in `connectedCallback` and replaces the inline
     * defaults. If the lang file only exposes strings (no subarray),
     * we return an empty array — the TS defaults are still there.
     */
    protected function resolveI18n(): array
    {
        $payload = trans('chatbot::chatbot');

        return is_array($payload) ? $payload : [];
    }

    /**
     * Allow `/chatbot?conversation_id=X` to deep-link a specific conversation
     * (for sharing, bookmarking, breadcrumb navigation). Validates ownership;
     * invalid / non-owned / missing values silently degrade to null so a
     * recipient who doesn't own the linked conversation still sees the page
     * (rendered with whatever localStorage holds, or empty).
     */
    protected function resolveInitialConversation(Request $request): int|string|null
    {
        $raw = $request->query('conversation_id');
        if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0) {
            return null;
        }

        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $exists = Conversation::query()
            ->forUser($user)
            ->whereKey($id)
            ->exists();

        return $exists ? $id : null;
    }

    /**
     * Returns the configured layout if it exists, or null for the standalone fallback.
     *
     * If `chatbot.page.layout` points to a view that does NOT exist, it logs an
     * actionable warning and degrades to standalone — the host may have forgotten
     * to publish the layout or have a typo. Breaking the page at runtime would be
     * worse UX than a fallback with a log.
     */
    protected function resolveLayout(): ?string
    {
        $layout = config('chatbot.page.layout');

        if (! is_string($layout) || $layout === '') {
            return null;
        }

        if (! ViewFactory::exists($layout)) {
            Log::warning(sprintf(
                '[chatbot] chatbot.page.layout="%s" does not exist in the host. '
                . 'The /chatbot page will render in standalone mode. '
                . 'Check the layout name or publish your own.',
                $layout,
            ));

            return null;
        }

        return $layout;
    }
}
