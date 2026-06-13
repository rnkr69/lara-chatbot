{{--
    E17 — Dedicated chat page extending a host layout.

    Used when `chatbot.page.layout` points to a view that exists
    (verified by `PageController::resolveLayout()`). The name of the section
    into which the content is injected is configurable via
    `chatbot.page.section` (default `content`).

    The widget bundle's `<script>` is included INLINE within the
    section (not in `@push('head')`) so it works with layouts that do not
    expose `@stack('head')` — it is safe to keep it `defer`d in the body.

    Expected variables (injected by `PageController`):
      - $layout            string   Name of the host layout (already validated).
      - $section           string   Section where the content is injected.
      - $assetUrl          string   URL of the <chatbot-widget.js> bundle.
      - $streamUrl         string   URL of the chat SSE endpoint.
      - $conversationsUrl  string   Base URL of the conversations CRUD (E10).
      - $theme             string   'light' | 'dark' | 'auto'.
--}}
@extends($layout)

@section($section)
    <div class="chatbot-page-host" style="height: 100%; min-height: calc(100vh - 4rem);">
        <chatbot-widget
            mode="page"
            data-endpoint="{{ $streamUrl }}"
            data-conversations-endpoint="{{ $conversationsUrl }}"
            data-theme="{{ $theme }}"
            data-i18n="{{ json_encode($i18n ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
            @if(auth()->check()) data-user-id="{{ auth()->id() }}" @endif
            @if(!empty($initialConversationId)) data-conversation-id="{{ $initialConversationId }}" @endif
        ></chatbot-widget>
    </div>
    <script src="{{ $assetUrl }}" defer></script>
@endsection
