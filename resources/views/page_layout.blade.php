{{--
    E17 — Página dedicada de chat extendiendo un layout del host.

    Se usa cuando `chatbot.page.layout` apunta a una vista que existe
    (verificada por `PageController::resolveLayout()`). El nombre de sección
    en la que se inyecta el contenido es configurable via
    `chatbot.page.section` (default `content`).

    El `<script>` del bundle del widget se incluye INLINE dentro de la
    sección (no en `@push('head')`) para que funcione con layouts que no
    expongan `@stack('head')` — es seguro mantenerlo `defer`d en el body.

    Variables esperadas (las inyecta `PageController`):
      - $layout            string   Nombre del layout del host (ya validado).
      - $section           string   Sección donde inyectar el contenido.
      - $assetUrl          string   URL del bundle <chatbot-widget.js>.
      - $streamUrl         string   URL del endpoint SSE de chat.
      - $conversationsUrl  string   URL base del CRUD de conversaciones (E10).
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
