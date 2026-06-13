{{--
    E17 — Dedicated chat page (`GET /chatbot`) in standalone mode.

    This view is served when `chatbot.page.layout` is null or points to a
    view that does not exist. It renders complete HTML from the package; it
    does not extend any host layout. When the host configures a valid layout,
    the controller uses `chatbot::page_layout` (which does `@extends`).

    v2.1.1 (#26) — standalone mode has no host chrome. If
    `chatbot.page.back_url` is set, the view paints a link at the top
    "← back to app" so the page is not a dead-end island.

    Expected variables (injected by `PageController`):
      - $assetUrl          string   URL of the <chatbot-widget.js> bundle.
      - $streamUrl         string   URL of the chat SSE endpoint.
      - $conversationsUrl  string   Base URL of the conversations CRUD (E10).
      - $theme             string   'light' | 'dark' | 'auto'.
      - $backUrl           ?string  v2.1.1 (#26) — URL of the "back to app"
                                     link. null = no link.

    To customize: `php artisan vendor:publish --tag=chatbot-views` and
    edit `resources/views/vendor/chatbot/page.blade.php`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('chatbot::chatbot.page_title') }}</title>
    <style>
        html, body { margin: 0; padding: 0; height: 100%; background: #f5f5f5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        #chatbot-page-root { display: flex; height: 100vh; width: 100vw; }
        chatbot-widget { flex: 1; min-width: 0; }
        /* v2.1.1 (#26) — "back to app" bar; only rendered when back_url is set. */
        .cb-standalone-bar { box-sizing: border-box; height: 40px; display: flex; align-items: center; padding: 0 16px; background: #fff; border-bottom: 1px solid #e3e3e3; font-size: 14px; }
        .cb-standalone-bar a { color: #2563eb; text-decoration: none; }
        .cb-standalone-bar a:hover { text-decoration: underline; }
        body.cb-has-back-bar #chatbot-page-root { height: calc(100vh - 40px); }
    </style>
    <script src="{{ $assetUrl }}" defer></script>
</head>
<body @class(['cb-has-back-bar' => ! empty($backUrl)])>
    @if(! empty($backUrl))
        <nav class="cb-standalone-bar">
            <a href="{{ $backUrl }}">&larr; {{ __('chatbot::chatbot.back_to_app') }}</a>
        </nav>
    @endif
    <div id="chatbot-page-root">
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
</body>
</html>
