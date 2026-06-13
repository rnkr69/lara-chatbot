{{--
    E4 — Dedicated Personal Dashboard page (`GET /chatbot/dashboard`)
    in standalone mode.

    This view is served when `chatbot.dashboard.layout` is null or points to
    a view that does not exist. It renders complete HTML from the package; it
    does not extend any host layout. When the host configures a valid layout,
    the controller uses `chatbot::dashboard_layout` (which does
    `@extends`).

    The actual JS bundle is built by E5 (`chatbot-dashboard.js` with gridstack
    + Chart.js + DashboardApp). In E4 we only leave the root + the JSON API
    URLs so the bundle, when it exists, consumes them directly.

    v2.1.1 (#26) — standalone mode has no host chrome. If
    `chatbot.dashboard.back_url` is set, the view paints a link at the top
    "← back to app" so the page is not a dead-end island.

    Expected variables (injected by `DashboardController`):
      - $assetUrl         string   URL of the <chatbot-dashboard.js> bundle.
      - $dashboardsUrl    string   Base URL of the JSON CRUD (E4).
      - $defaultSlug      ?string  Slug of the user's default dashboard
                                    (or ?dashboard=… if it came in the query).
                                    null when the user has none.
      - $theme            string   'light' | 'dark' | 'auto'.
      - $backUrl          ?string  v2.1.1 (#26) — URL of the "back to app"
                                    link. null = no link.

    To customize: `php artisan vendor:publish --tag=chatbot-views` and
    edit `resources/views/vendor/chatbot/dashboard.blade.php`.
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
