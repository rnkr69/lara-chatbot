<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View as ViewFactory;
use Rnkr69\LaraChatbot\Models\Dashboard;

/**
 * E4 — `GET /{chatbot.route.prefix}/dashboard` (default `/chatbot/dashboard`).
 *
 * Serves the publishable `chatbot::dashboard` view that the separate E5
 * bundle (`chatbot-dashboard.js`) will mount. In E4 we only deliver the root + the
 * JSON API URLs (E4) + the user's default slug.
 *
 * Pattern identical to `PageController` (E17, D16):
 *
 *   - If `chatbot.dashboard.layout` is a string AND `View::exists($layout)` →
 *     `chatbot::dashboard_layout` (`@extends($layout) @section($section)`).
 *   - If null or the view does not exist (with a log warning) → `chatbot::dashboard`
 *     standalone (full HTML from the package).
 *
 * Optional param `?dashboard={slug}` deep-links a specific dashboard. If
 * the slug does not exist or does not belong to the user, **NO 404**: the frontend (E5)
 * paints the empty state / falls back to the default. The HTML view is always 200 — with no
 * dashboards the user sees a "create your first dashboard" CTA.
 *
 * v2.1.1 (#26): if `chatbot.dashboard.mount_widget` (default true), both
 * views mount the floating `<chatbot-widget>` so the user can
 * pin from the dashboard itself; and the standalone view paints a
 * "back to app" link when `chatbot.dashboard.back_url` is set.
 *
 * Per-user authorization is inherited from the group middleware
 * (`config('chatbot.route.middleware')` = `['web', 'auth']`).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $layout  = $this->resolveLayout();
        $section = (string) config('chatbot.dashboard.section', 'content');
        if ($section === '') {
            $section = 'content';
        }

        $assetPath = (string) config('chatbot.dashboard.asset_path', 'vendor/chatbot/chatbot-dashboard.js');
        $assetUrl  = asset($assetPath);

        // Two views: `chatbot::dashboard` is fully standalone (no
        // `@extends`); `chatbot::dashboard_layout` extends the host layout.
        // Splitting them is necessary because Blade compiles `@extends(...)`
        // to a footer that runs unconditionally — wrapping it in `@if(...)`
        // would still try to load a null layout and explode with "View []
        // not found" (same pattern as E17, see D16).
        $viewName = $layout !== null ? 'chatbot::dashboard_layout' : 'chatbot::dashboard';

        $chartRenderer = (string) config('chatbot.dashboard.chart_renderer', 'chartjs');
        if (! in_array($chartRenderer, ['chartjs', 'none'], true)) {
            $chartRenderer = 'chartjs';
        }

        // v2.1.1 (#26) — mount the floating widget on the dashboard so the
        // user can pin from here (the page whose job is to *collect* pinned
        // blocks was the one place you couldn't generate them). The widget
        // bundle URL is only resolved when the feature is on.
        $mountWidget = filter_var(config('chatbot.dashboard.mount_widget', true), FILTER_VALIDATE_BOOLEAN);

        $defaultSlug = $this->resolveDefaultSlug($request);

        return view($viewName, [
            'layout'         => $layout,
            'section'        => $section,
            'assetUrl'       => $assetUrl,
            'dashboardsUrl'  => route('chatbot.dashboards.index'),
            'theme'          => (string) config('chatbot.widget.theme', 'auto'),
            'defaultSlug'    => $defaultSlug,
            // v2.2 — page_context auto-inject. JSON with slug/name/is_default/
            // widgets[] of the dashboard that opens initially. The JS bundle
            // reads it on-boot and calls `window.Chatbot.setPageContext({dashboard:
            // {...}})` so the LLM can resolve "the KPIs widget"
            // → widget_id without fuzzy matching. No defaultSlug → empty array.
            'dashboardContext' => $this->resolveDashboardContext($request, $defaultSlug),
            'chartRenderer'  => $chartRenderer,
            'useBootstrap'   => $this->resolveUseBootstrap($layout),
            // v2.1 (#17) — gated the per-widget "View source" (👁) button.
            // v2.1.3: the button is gone; we keep stamping `data-debug` from
            // `config('app.debug')` for forward-compat with any future host
            // affordance, but the bundle no longer reads it.
            'debug'          => (bool) config('app.debug', false),
            'i18n'           => $this->resolveI18n(),
            // v2.1.1 (#26) — floating widget mount + standalone "back to app" link.
            'mountWidget'      => $mountWidget,
            'widgetAssetUrl'   => $mountWidget
                ? asset((string) config('chatbot.widget.asset_path', 'vendor/chatbot/chatbot-widget.js'))
                : null,
            'streamUrl'        => route('chatbot.stream'),
            'conversationsUrl' => route('chatbot.conversations.index'),
            'backUrl'          => $this->resolveBackUrl(),
            // v2.1.3 (#34) — optional host view to `@include` inside the
            // dashboard section. Replaces the broken `chatbot_dashboard_extras`
            // stack (the stack lived inside an already-captured @section, so
            // pushes from the host's `$layout` view never reached it).
            'extrasView'       => $this->resolveExtrasView(),
        ]);
    }

    /**
     * v2.2 — Structure of the `data-dashboard-context` that the JS bundle reads at
     * boot and translates to `Chatbot.setPageContext({dashboard: {...}})`. It gives
     * the LLM direct resolution of "the KPIs widget" → `widget_id` from
     * `page_context.dashboard.widgets`, without going through fuzzy matching or
     * hallucinations (the PR-B edit/delete tools require an ID, not a title).
     *
     * Binary cap: if the widgets exceed `chatbot.limits.page_context_kb`
     * (default 16 KB) after serializing, the subset is reduced to `id` + `title`
     * per widget — enough for the LLM to resolve without losing body
     * in the system prompt. Below the cap, `block_type`,
     * `position`, `refresh_policy`, `last_refresh_status` are included so the LLM
     * can also reason about state and geometry.
     *
     * Returns `[]` (instead of null) when there is no dashboard to inject —
     * the blade emits the attribute with `[]` and the JS bundle detects the emptiness
     * so as not to set page_context. This prevents the LLM from "resolving" widgets
     * that no longer exist.
     *
     * @return array<string, mixed>
     */
    protected function resolveDashboardContext(Request $request, ?string $slug): array
    {
        $user = $request->user();

        if (! $user instanceof Model || $slug === null) {
            return [];
        }

        /** @var Dashboard|null $dashboard */
        $dashboard = Dashboard::query()
            ->forUser($user)
            ->where('slug', $slug)
            ->with(['widgets' => function ($q): void {
                $q->orderBy('order_index')->orderBy('id');
            }])
            ->first();

        if ($dashboard === null) {
            return [];
        }

        $widgets = $dashboard->widgets->map(static function ($w): array {
            return [
                'id'                  => (int) $w->id,
                'title'               => $w->title,
                'block_type'          => (string) $w->block_type,
                'position'            => is_array($w->position) ? $w->position : null,
                'refresh_policy'      => $w->refresh_policy?->value,
                'last_refresh_status' => $w->last_refresh_status?->value,
            ];
        })->all();

        $context = [
            'slug'       => (string) $dashboard->slug,
            'name'       => (string) $dashboard->name,
            'is_default' => (bool) $dashboard->is_default,
            'widgets'    => $widgets,
        ];

        // Cap: the chat's full page_context has a binary limit so as
        // not to inflate the system prompt. If the widget list pushes us past
        // that cap, we degrade to `id` + `title` (enough for the LLM to
        // match titles and emit widget_ids). Better a page_context
        // that is functionally useful but limited than none.
        $limitKb = (int) config('chatbot.limits.page_context_kb', 16);
        $limit   = max(1, $limitKb) * 1024;

        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && strlen($encoded) > $limit) {
            $context['widgets'] = $dashboard->widgets->map(static fn ($w): array => [
                'id'    => (int) $w->id,
                'title' => $w->title,
            ])->all();
            $context['widgets_truncated'] = true;
        }

        return $context;
    }

    /**
     * v2.1.3 (#34) — name of an optional host view that `dashboard_layout`
     * `@include`s inside the section, just below the dashboard root. Same
     * validation/degrade policy as `resolveLayout()` so a typo logs a
     * warning rather than crashing the dashboard.
     */
    protected function resolveExtrasView(): ?string
    {
        $extras = config('chatbot.dashboard.extras_view');

        if (! is_string($extras) || $extras === '') {
            return null;
        }

        if (! ViewFactory::exists($extras)) {
            Log::warning(sprintf(
                '[chatbot] chatbot.dashboard.extras_view="%s" does not exist in the host. '
                . 'The /chatbot/dashboard page will render without extras. '
                . 'Check the view name or remove the key from the config.',
                $extras,
            ));

            return null;
        }

        return $extras;
    }

    /**
     * v2.1.1 (#26) — URL of the "← back to app" link in the standalone
     * view. It is only painted if it is a non-empty string; null leaves the
     * page without a link. In `layout` mode the navigation is provided by the
     * host chrome, so the layout view ignores it.
     */
    protected function resolveBackUrl(): ?string
    {
        $backUrl = config('chatbot.dashboard.back_url');

        return is_string($backUrl) && $backUrl !== '' ? $backUrl : null;
    }

    /**
     * v2.1 / #19 — decides whether the dashboard should use the host's
     * Bootstrap primitives (when available) instead of the package's own
     * CSS for the block renderers (`table`/`card`/`list`).
     *
     * `chatbot.backpack.use_bootstrap`:
     *   - `true`/`false` (or their strings) → forces the mode.
     *   - `'auto'` (default) → true only if the dashboard is in
     *     `layout` mode (inherits the host's `<head>`, where Backpack's
     *     Bootstrap lives) AND the Backpack package is installed. Standalone mode
     *     is the package's own HTML page without the host's `<head>` — there
     *     is never any Bootstrap there, so `auto` always resolves to false.
     */
    protected function resolveUseBootstrap(?string $layout): bool
    {
        $setting = config('chatbot.backpack.use_bootstrap', 'auto');

        if (is_bool($setting)) {
            return $setting;
        }

        $normalized = is_string($setting) ? strtolower(trim($setting)) : '';

        if (in_array($normalized, ['true', '1', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'off', 'no'], true)) {
            return false;
        }

        // 'auto' (or any unrecognized value): Bootstrap is only
        // reachable in layout mode and with the Backpack package installed.
        return $layout !== null
            && class_exists('Backpack\\CRUD\\BackpackServiceProvider');
    }

    /**
     * v2.0 / E9 — PHP → JS bridge, identical to `PageController`'s. The blade
     * `json_encode`s it in `data-i18n` on `#chatbot-dashboard-root`; the
     * dashboard bundle drains the `dashboard.*` and `dashboard.kpi.*` subtree
     * from here.
     */
    protected function resolveI18n(): array
    {
        $payload = trans('chatbot::chatbot');

        return is_array($payload) ? $payload : [];
    }

    /**
     * Resolves the slug of the dashboard to open. Priority:
     *
     *   1. `?dashboard={slug}` valid and owned by the user.
     *   2. the user's `is_default=true`.
     *   3. null — no dashboards (the view paints the empty state via E5).
     *
     * Like `PageController::resolveInitialConversation()`, if the query's
     * slug is not owned we degrade to null (not 404) — the deep-link
     * shared by mistake does not break the page.
     */
    protected function resolveDefaultSlug(Request $request): ?string
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            return null;
        }

        $requested = $request->query('dashboard');

        if (is_string($requested) && $requested !== '') {
            $exists = Dashboard::query()
                ->forUser($user)
                ->where('slug', $requested)
                ->exists();

            if ($exists) {
                return $requested;
            }
        }

        /** @var Dashboard|null $default */
        $default = Dashboard::query()
            ->forUser($user)
            ->default()
            ->first();

        return $default?->slug;
    }

    /**
     * Same pattern as `PageController::resolveLayout()`. If the configured
     * layout's view does NOT exist, it logs a warning and degrades to standalone
     * instead of breaking the page at runtime.
     */
    protected function resolveLayout(): ?string
    {
        $layout = config('chatbot.dashboard.layout');

        if (! is_string($layout) || $layout === '') {
            return null;
        }

        if (! ViewFactory::exists($layout)) {
            Log::warning(sprintf(
                '[chatbot] chatbot.dashboard.layout="%s" does not exist in the host. '
                . 'The /chatbot/dashboard page will render in standalone mode. '
                . 'Check the layout name or publish your own.',
                $layout,
            ));

            return null;
        }

        return $layout;
    }
}
