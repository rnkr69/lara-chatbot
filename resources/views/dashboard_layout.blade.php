{{--
    E4 — Dedicated Personal Dashboard page extending a host layout.

    Used when `chatbot.dashboard.layout` points to a view that exists
    (verified by `DashboardController::resolveLayout()`). The name of the
    section into which the content is injected is configurable via
    `chatbot.dashboard.section` (default `content`).

    The dashboard bundle's `<script>` is included INLINE within the
    section (not in `@push('head')`) so it works with layouts that do not
    expose `@stack('head')` — it is safe to keep it `defer`d in the body.

    v2.1.1 (#26) — when `chatbot.dashboard.mount_widget` is active (default),
    this view also mounts the floating `<chatbot-widget>` via
    `@push('after_scripts')`, so the user can pin FROM the dashboard
    itself. Backpack layouts —the documented target of
    `chatbot.dashboard.layout`— expose `@stack('after_scripts')`. A host that
    prefers to inject the widget on its own sets `mount_widget` to false.

    v2.1.3 (#34) — replaces the `@stack('chatbot_dashboard_extras')` that
    v2.1.2 (#31) had attempted to introduce. The stack lived inside
    `@section($section)…@endsection` and that meant its content was
    captured during the evaluation of the child view (this one), BEFORE
    the host's `$layout` ran its body — a `@push('chatbot_dashboard_extras')`
    from the `$layout` (the usage we documented) ran too late
    and the stack rendered empty.

    The new extension point is the `chatbot.dashboard.extras_view` config:
    the name of a host Blade view (e.g. `'admin._chatbot_widget'`).
    The controller validates it with `View::exists()` and passes it as `$extrasView`.
    Here we `@include` it synchronously right below the dashboard root.
    Being a normal include, the host view's body is evaluated in this
    same context: any `@push('after_scripts')` lands where it should
    (at the end of Backpack's top layout), and any HTML markup is injected
    here. Typical use case: the host mounts its `<chatbot-widget>` + the
    `chatbot-actions.js` bundle and composes with the v2.1.3 (#35) upgrade shim
    without having to hack the load order.

    Expected variables (injected by `DashboardController`):
      - $layout           string   Name of the host layout (already validated).
      - $section          string   Section where the content is injected.
      - $assetUrl         string   URL of the <chatbot-dashboard.js> bundle.
      - $dashboardsUrl    string   Base URL of the JSON CRUD (E4).
      - $defaultSlug      ?string  Slug of the user's default dashboard.
      - $theme            string   'light' | 'dark' | 'auto'.
      - $mountWidget      bool     v2.1.1 (#26) — mount the floating widget.
      - $widgetAssetUrl   ?string  URL of the <chatbot-widget.js> bundle (null if !$mountWidget).
      - $streamUrl        string   Chat SSE endpoint.
      - $conversationsUrl string   Base URL of the conversations CRUD.
      - $extrasView       ?string  v2.1.3 (#34) — name of the host view
                                    to `@include` below the root. null = no extras.
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

    {{-- v2.1.3 (#34) — extension point replacing the broken stack from #31.
         When `chatbot.dashboard.extras_view` points to an existing view,
         the synchronous `@include` evaluates it here: any `<chatbot-widget>` or
         `<script>` the host paints slips in below the dashboard root, and
         any internal `@push('after_scripts')` lands in Backpack's top
         layout as it should. Without the key, the `@if` is false and the branch
         renders nothing (no cost). --}}
    @if(! empty($extrasView))
        @include($extrasView)
    @endif
@endsection

@if(($mountWidget ?? false) && !empty($widgetAssetUrl))
    {{-- v2.1.1 (#26) — the floating `<chatbot-widget>` on the dashboard page
         itself: without this, the page whose purpose is to collect pinned
         blocks is the only one where you cannot generate them. Pushed to
         `after_scripts` to compose with the host chrome. --}}
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
