<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Illuminate\Support\Facades\RateLimiter;
use Rnkr69\LaraChatbot\Dashboard\PinException;
use Rnkr69\LaraChatbot\Dashboard\PinService;
use Rnkr69\LaraChatbot\Dashboard\ReplayService;
use Rnkr69\LaraChatbot\Dashboard\WidgetCrudService;
use Rnkr69\LaraChatbot\Http\Requests\MoveWidgetRequest;
use Rnkr69\LaraChatbot\Http\Requests\PinWidgetRequest;
use Rnkr69\LaraChatbot\Http\Resources\DashboardWidgetResource;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON CRUD + replay endpoints for `DashboardWidget` (v2.0 / E4, plan §4.7).
 *
 *   - `store`      Pin: creates a widget from `{block_id, snapshot, source,
 *                  suggested_title?, page_context?, position?}`. Validation
 *                  cascade: widget cap → tool exists → tool
 *                  pinnable+Auto → snapshot truncated if it exceeds the cap →
 *                  source_signature → filtered and sanitized
 *                  page_context_snapshot → insert with last_refresh_status='fresh'.
 *   - `update`     Move/resize/retitle/change of refresh_policy. Selective
 *                  fill() via `$request->has(...)`.
 *   - `refresh`    Manual replay: rate-limited, delegates to `ReplayService::replay`.
 *   - `destroy`    Soft-delete (unpin). 204.
 *   - `refreshAll` Bulk SSE: rate-limited, runs `ReplayService::replayBulk`
 *                  (parallelism + chunking in E3) and emits one
 *                  `widget_refreshed` frame per result + a final `done`.
 *
 * 404-not-403 policy: any foreign or soft-deleted slug/widget → 404.
 *
 * Rate limit: `chatbot.dashboard.replay.rate_limit_per_user_per_minute`
 * (default 60) applies ONLY to `refresh` + `refreshAll`. The CRUD (pin/unpin/
 * move) does not enter the throttle: the real cost is in re-running tools, not
 * in writing rows. Bulk counts as 1 hit (the E3 concurrency cap protects
 * internally).
 */
class ApiDashboardWidgetController extends Controller
{
    public function __construct(
        protected ToolRegistry $registry,
        protected ReplayService $replayService,
        protected PinService $pinService,
        protected WidgetCrudService $widgetCrud,
    ) {}

    public function store(PinWidgetRequest $request, string $slug): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboard = $this->findOwnedOr404($user, $slug);

        /** @var array{tool: string, args?: array<string,mixed>, page_context_keys?: array<int,string>} $source */
        $source = (array) $request->input('source');
        $toolName = (string) ($source['tool'] ?? '');

        $tool = $this->registry->get($toolName);

        if ($tool === null) {
            return response()->json([
                'message' => sprintf('Tool `%s` is not registered.', $toolName),
                'errors'  => [
                    'source.tool' => [sprintf('Tool `%s` is not registered.', $toolName)],
                ],
            ], 422);
        }

        // Upstream enforcement (parity with E3): pinnable() === true AND
        // confirmation() === Auto. Mutating tools never reach the
        // dashboard, even if they override pinnable() by mistake. We keep
        // the inline pre-check to preserve the historical 422 shape
        // (the JS client assumes `errors['source.tool']`); `PinService`
        // re-checks it as defense-in-depth.
        if (! $tool->pinnable() || $tool->confirmation() !== ConfirmationLevel::Auto) {
            return response()->json([
                'message' => sprintf(
                    'Tool `%s` is not pinnable (requires pinnable() === true and confirmation === Auto).',
                    $toolName,
                ),
                'errors'  => [
                    'source.tool' => [sprintf('Tool `%s` is not pinnable.', $toolName)],
                ],
            ], 422);
        }

        $blockType = (string) $request->input('block_type');
        $rawSnapshot = is_array($request->input('snapshot')) ? (array) $request->input('snapshot') : [];
        $blockData = is_array($rawSnapshot['data'] ?? null) ? $rawSnapshot['data'] : [];

        $sourceArgs = is_array($source['args'] ?? null) ? $source['args'] : [];
        $pageContextKeys = is_array($source['page_context_keys'] ?? null) ? $source['page_context_keys'] : [];

        // The `block` descriptor passed to `PinService` has the same shape
        // the tool emits in `ToolResult::blocks`: `type` + `data` +
        // optional `id` (audit) and `ordinal` (replay matching for
        // multi-block tools, v2.1.2 #27). The JS client supplies the last
        // two when it knows them; if absent, replay falls back to ordinal 0.
        $block = [
            'type' => $blockType,
            'data' => $blockData,
        ];

        $blockId = $request->input('block_id');
        if (is_string($blockId) && $blockId !== '') {
            $block['id'] = $blockId;
        }

        $blockOrdinal = $request->input('block_ordinal');
        if (is_int($blockOrdinal) && $blockOrdinal >= 0) {
            $block['ordinal'] = $blockOrdinal;
        }

        $rawPosition = $request->input('position');

        try {
            $widget = $this->pinService->pin(
                dashboard: $dashboard,
                sourceTool: $tool,
                sourceArgs: $sourceArgs,
                block: $block,
                suggestedTitle: $request->input('suggested_title'),
                pageContext: (array) $request->input('page_context', []),
                pageContextKeys: $pageContextKeys,
                position: is_array($rawPosition) ? $rawPosition : null,
            );
        } catch (PinException $e) {
            return $this->mapPinException($e);
        }

        return (new DashboardWidgetResource($widget))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Maps `PinException` categories to the controller's historical JSON 422
     * shape. The service propagates the exception with
     * `category` + `context`; here we preserve messages identical to the
     * v2.1.x controller so that tests and JS clients detect no change.
     */
    protected function mapPinException(PinException $e): JsonResponse
    {
        return match ($e->category) {
            'cap_reached' => response()->json([
                'message' => sprintf(
                    'This dashboard has reached the maximum of %d widgets. Unpin one before adding more.',
                    (int) ($e->context['cap'] ?? 0),
                ),
                'errors'  => [
                    'dashboard' => [sprintf('Maximum of %d widgets reached.', (int) ($e->context['cap'] ?? 0))],
                ],
            ], 422),
            'not_pinnable' => response()->json([
                'message' => sprintf(
                    'Tool `%s` is not pinnable (requires pinnable() === true and confirmation === Auto).',
                    (string) ($e->context['tool'] ?? ''),
                ),
                'errors'  => [
                    'source.tool' => [sprintf('Tool `%s` is not pinnable.', (string) ($e->context['tool'] ?? ''))],
                ],
            ], 422),
            default => response()->json([
                'message' => $e->getMessage(),
            ], 422),
        };
    }

    public function update(MoveWidgetRequest $request, string $slug, int $id): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboard = $this->findOwnedOr404($user, $slug);
        $widget    = $this->findWidgetOr404($dashboard, $id);

        // Only the keys PRESENT in the request enter the diff — PATCH
        // semantics identical to the v2.1.x controller: absent ones are
        // preserved, `title: null` clears. `WidgetCrudService::update()`
        // normalizes position and validates `refresh_policy` against the enum.
        $changes = [];
        if ($request->has('position')) {
            $changes['position'] = $request->input('position');
        }
        if ($request->has('title')) {
            $changes['title'] = $request->input('title');
        }
        if ($request->has('refresh_policy')) {
            $changes['refresh_policy'] = (string) $request->input('refresh_policy');
        }

        $this->widgetCrud->update($widget, $changes);

        return (new DashboardWidgetResource($widget))->response();
    }

    public function refresh(Request $request, string $slug, int $id): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if (($limit = $this->checkRefreshRateLimit($user)) instanceof Response) {
            return new JsonResponse([
                'message' => 'Too many refresh requests. Try again later.',
            ], 429, $limit->headers->all());
        }

        $dashboard = $this->findOwnedOr404($user, $slug);
        $widget    = $this->findWidgetOr404($dashboard, $id);

        $result = $this->replayService->replay($widget, $user);

        // Flat `WidgetRefreshedFrame` shape — identical to the frames the
        // bulk SSE stream emits, so both refresh endpoints share one
        // contract the bundle can consume without branching.
        return new JsonResponse([
            'data' => [
                'widget_id'         => $widget->id,
                'status'            => $result->status->value,
                'snapshot'          => $result->snapshot,
                'error'             => $result->error,
                'last_refreshed_at' => $result->lastRefreshedAt->toIso8601String(),
            ],
        ]);
    }

    public function refreshAll(Request $request, string $slug): SymfonyResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        if (($limit = $this->checkRefreshRateLimit($user)) instanceof Response) {
            return $limit;
        }

        $dashboard = $this->findOwnedOr404($user, $slug);
        $service   = $this->replayService;

        $callback = function () use ($dashboard, $user, $service): void {
            $results = $service->replayBulk($dashboard, $user);

            foreach ($results as $widgetId => $result) {
                $payload = [
                    'widget_id'         => $widgetId,
                    'status'            => $result->status->value,
                    'snapshot'          => $result->snapshot,
                    'error'             => $result->error,
                    'last_refreshed_at' => $result->lastRefreshedAt->toIso8601String(),
                ];

                echo "event: widget_refreshed\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

                if (function_exists('ob_get_level') && ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            }

            echo "event: done\n";
            echo "data: {\"widget_count\":" . count($results) . "}\n\n";

            if (function_exists('ob_get_level') && ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        };

        return new StreamedResponse($callback, 200, [
            'Content-Type'      => 'text/event-stream; charset=UTF-8',
            'Cache-Control'     => 'no-cache, private',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    public function destroy(Request $request, string $slug, int $id): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboard = $this->findOwnedOr404($user, $slug);
        $widget    = $this->findWidgetOr404($dashboard, $id);

        $this->widgetCrud->delete($widget);

        return response()->noContent(); // 204
    }

    /**
     * Mirror of the `/chatbot/stream` rate limiter (E09): key per user,
     * 60 s window, configurable max. Returns a 429 Response if exceeded or
     * null if within the limit (and records the hit). Applies ONLY to refresh +
     * refreshAll — the CRUD does not enter the throttle (decision §4.10 / E4).
     */
    protected function checkRefreshRateLimit(mixed $user): ?Response
    {
        $max = (int) config('chatbot.dashboard.replay.rate_limit_per_user_per_minute', 60);

        if ($max <= 0) {
            return null;
        }

        $userKey = $user instanceof Model ? $user->getKey() : $user?->getAuthIdentifier();
        $key     = "chatbot:dashboard-refresh:{$userKey}";

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response('', 429, [
                'Retry-After'           => (string) $retryAfter,
                'X-RateLimit-Reset'     => (string) $retryAfter,
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    protected function findOwnedOr404(mixed $user, string $slug): Dashboard
    {
        return Dashboard::query()
            ->forUser($user)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    protected function findWidgetOr404(Dashboard $dashboard, int $id): DashboardWidget
    {
        return $dashboard->widgets()
            ->whereKey($id)
            ->firstOrFail();
    }
}
