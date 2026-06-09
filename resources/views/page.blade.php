{{--
    E17 — Página dedicada de chat (`GET /chatbot`) en modo standalone.

    Esta vista se sirve cuando `chatbot.page.layout` es null o apunta a una
    vista que no existe. Renderiza HTML completo desde el paquete; no extiende
    ningún layout del host. Cuando el host configura un layout válido, el
    controller usa `chatbot::page_layout` (que sí hace `@extends`).

    v2.1.1 (#26) — el modo standalone no tiene chrome del host. Si
    `chatbot.page.back_url` está seteado, la vista pinta arriba un enlace
    "← volver a la app" para que la página no sea una isla sin salida.

    Variables esperadas (las inyecta `PageController`):
      - $assetUrl          string   URL del bundle <chatbot-widget.js>.
      - $streamUrl         string   URL del endpoint SSE de chat.
      - $conversationsUrl  string   URL base del CRUD de conversaciones (E10).
      - $theme             string   'light' | 'dark' | 'auto'.
      - $backUrl           ?string  v2.1.1 (#26) — URL del enlace "volver a la
                                     app". null = sin enlace.

    Para personalizar: `php artisan vendor:publish --tag=chatbot-views` y
    edita `resources/views/vendor/chatbot/page.blade.php`.
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
