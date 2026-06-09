{{--
    E4 — Página dedicada del Personal Dashboard extendiendo un layout del host.

    Se usa cuando `chatbot.dashboard.layout` apunta a una vista que existe
    (verificada por `DashboardController::resolveLayout()`). El nombre de
    sección en la que se inyecta el contenido es configurable via
    `chatbot.dashboard.section` (default `content`).

    El `<script>` del bundle del dashboard se incluye INLINE dentro de la
    sección (no en `@push('head')`) para que funcione con layouts que no
    expongan `@stack('head')` — es seguro mantenerlo `defer`d en el body.

    v2.1.1 (#26) — cuando `chatbot.dashboard.mount_widget` está activo (default),
    esta vista monta además el `<chatbot-widget>` flotante vía
    `@push('after_scripts')`, para que el usuario pueda pinear DESDE el propio
    dashboard. Los layouts de Backpack —destino documentado de
    `chatbot.dashboard.layout`— exponen `@stack('after_scripts')`. El host que
    prefiera inyectar el widget por su cuenta pone `mount_widget` a false.

    v2.1.3 (#34) — reemplaza el `@stack('chatbot_dashboard_extras')` que
    v2.1.2 (#31) había intentado introducir. El stack vivía dentro de
    `@section($section)…@endsection` y eso significaba que su contenido se
    capturaba durante la evaluación de la vista hija (esta), ANTES de que
    el `$layout` del host ejecutase su body — un `@push('chatbot_dashboard_extras')`
    desde el `$layout` (la usage que documentábamos) corría demasiado tarde
    y el stack se renderizaba vacío.

    El nuevo punto de extensión es el config `chatbot.dashboard.extras_view`:
    nombre de una vista Blade del host (p.ej. `'admin._chatbot_widget'`).
    El controller la valida con `View::exists()` y la pasa como `$extrasView`.
    Aquí la `@include`amos síncronamente justo debajo del root del dashboard.
    Al ser un include normal, el body de la vista del host se evalúa en este
    mismo contexto: cualquier `@push('after_scripts')` aterriza donde toca
    (al final del layout top de Backpack), y cualquier markup HTML se inyecta
    aquí. Caso de uso típico: el host monta su `<chatbot-widget>` + el bundle
    de `chatbot-actions.js` y compone con el shim upgrade del v2.1.3 (#35)
    sin tener que hackear el orden de carga.

    Variables esperadas (las inyecta `DashboardController`):
      - $layout           string   Nombre del layout del host (ya validado).
      - $section          string   Sección donde inyectar el contenido.
      - $assetUrl         string   URL del bundle <chatbot-dashboard.js>.
      - $dashboardsUrl    string   URL base del CRUD JSON (E4).
      - $defaultSlug      ?string  Slug del dashboard default del usuario.
      - $theme            string   'light' | 'dark' | 'auto'.
      - $mountWidget      bool     v2.1.1 (#26) — montar el widget flotante.
      - $widgetAssetUrl   ?string  URL del bundle <chatbot-widget.js> (null si !$mountWidget).
      - $streamUrl        string   Endpoint SSE de chat.
      - $conversationsUrl string   URL base del CRUD de conversaciones.
      - $extrasView       ?string  v2.1.3 (#34) — nombre de la vista del host
                                    a `@include` debajo del root. null = sin extras.
--}}
@extends($layout)

@section($section)
    <div
        id="chatbot-dashboard-root"
        class="chatbot-dashboard-host"
        style="height: 100%; min-height: calc(100vh - 4rem);"
        data-dashboards-endpoint="{{ $dashboardsUrl }}"
        data-theme="{{ $theme }}"
        data-chart-renderer="{{ $chartRenderer ?? 'chartjs' }}"
        data-use-bootstrap="{{ ($useBootstrap ?? false) ? '1' : '0' }}"
        data-debug="{{ ($debug ?? false) ? '1' : '0' }}"
        data-i18n="{{ json_encode($i18n ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
        @if(auth()->check()) data-user-id="{{ auth()->id() }}" @endif
        @if(!empty($defaultSlug)) data-default-slug="{{ $defaultSlug }}" @endif
        data-dashboard-context="{{ json_encode($dashboardContext ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
    ></div>
    <script src="{{ $assetUrl }}" defer></script>

    {{-- v2.1.3 (#34) — punto de extensión sustituye al stack roto de #31.
         Cuando `chatbot.dashboard.extras_view` apunta a una vista existente,
         el `@include` síncrono la evalúa aquí: cualquier `<chatbot-widget>` o
         `<script>` que el host pinte se cuela bajo el root del dashboard, y
         cualquier `@push('after_scripts')` interno aterriza en el layout top
         de Backpack como debía. Sin la clave, el `@if` es false y la rama no
         renderiza nada (sin coste). --}}
    @if(! empty($extrasView))
        @include($extrasView)
    @endif
@endsection

@if(($mountWidget ?? false) && !empty($widgetAssetUrl))
    {{-- v2.1.1 (#26) — el `<chatbot-widget>` flotante en la propia página del
         dashboard: sin esto, la página cuyo propósito es coleccionar bloques
         pineados es la única donde no puedes generarlos. Empujado a
         `after_scripts` para componer con el chrome del host. --}}
    @push('after_scripts')
        <chatbot-widget
            data-endpoint="{{ $streamUrl }}"
            data-conversations-endpoint="{{ $conversationsUrl }}"
            data-theme="{{ $theme }}"
            data-i18n="{{ json_encode($i18n ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
            @if(auth()->check()) data-user-id="{{ auth()->id() }}" @endif
        ></chatbot-widget>
        <script src="{{ $widgetAssetUrl }}" defer></script>
    @endpush
@endif
