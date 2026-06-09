{{--
    E4 — Página dedicada del Personal Dashboard (`GET /chatbot/dashboard`)
    en modo standalone.

    Esta vista se sirve cuando `chatbot.dashboard.layout` es null o apunta a
    una vista que no existe. Renderiza HTML completo desde el paquete; no
    extiende ningún layout del host. Cuando el host configura un layout
    válido, el controller usa `chatbot::dashboard_layout` (que sí hace
    `@extends`).

    El bundle JS real lo construye E5 (`chatbot-dashboard.js` con gridstack
    + Chart.js + DashboardApp). En E4 sólo dejamos el root + las URLs de la
    API JSON para que el bundle, cuando exista, las consuma directamente.

    v2.1.1 (#26) — el modo standalone no tiene chrome del host. Si
    `chatbot.dashboard.back_url` está seteado, la vista pinta arriba un enlace
    "← volver a la app" para que la página no sea una isla sin salida.

    Variables esperadas (las inyecta `DashboardController`):
      - $assetUrl         string   URL del bundle <chatbot-dashboard.js>.
      - $dashboardsUrl    string   URL base del CRUD JSON (E4).
      - $defaultSlug      ?string  Slug del dashboard default del usuario
                                    (o ?dashboard=… si vino en query).
                                    null cuando el usuario no tiene ninguno.
      - $theme            string   'light' | 'dark' | 'auto'.
      - $backUrl          ?string  v2.1.1 (#26) — URL del enlace "volver a la
                                    app". null = sin enlace.

    Para personalizar: `php artisan vendor:publish --tag=chatbot-views` y
    edita `resources/views/vendor/chatbot/dashboard.blade.php`.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('chatbot::chatbot.dashboard_title') }}</title>
    <style>
        html, body { margin: 0; padding: 0; height: 100%; background: #f5f5f5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        /* Only size the root before the deferred bundle loads — never set
           `display`. The bundle's CSS owns the layout (`display: grid` plus a
           responsive media query); an ID selector here would beat it on
           specificity and collapse the grid. */
        #chatbot-dashboard-root { height: 100vh; width: 100vw; }
        /* v2.1.1 (#26) — "back to app" bar; only rendered when back_url is set. */
        .cb-standalone-bar { box-sizing: border-box; height: 40px; display: flex; align-items: center; padding: 0 16px; background: #fff; border-bottom: 1px solid #e3e3e3; font-size: 14px; }
        .cb-standalone-bar a { color: #2563eb; text-decoration: none; }
        .cb-standalone-bar a:hover { text-decoration: underline; }
        body.cb-has-back-bar #chatbot-dashboard-root { height: calc(100vh - 40px); }
    </style>
    <script src="{{ $assetUrl }}" defer></script>
</head>
<body @class(['cb-has-back-bar' => ! empty($backUrl)])>
    @if(! empty($backUrl))
        <nav class="cb-standalone-bar">
            <a href="{{ $backUrl }}">&larr; {{ __('chatbot::chatbot.back_to_app') }}</a>
        </nav>
    @endif
    <div
        id="chatbot-dashboard-root"
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
</body>
</html>
