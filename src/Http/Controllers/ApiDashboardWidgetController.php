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
 * CRUD JSON + replay endpoints de `DashboardWidget` (v2.0 / E4, plan §4.7).
 *
 *   - `store`      Pin: crea widget desde `{block_id, snapshot, source,
 *                  suggested_title?, page_context?, position?}`. Cascada de
 *                  validación: cap widgets → tool existente → tool
 *                  pinnable+Auto → snapshot truncado si excede cap →
 *                  source_signature → page_context_snapshot filtrado y
 *                  sanitizado → insert con last_refresh_status='fresh'.
 *   - `update`     Move/resize/retitle/cambio de refresh_policy. fill()
 *                  selectivo por `$request->has(...)`.
 *   - `refresh`    Replay manual: rate-limited, delega a `ReplayService::replay`.
 *   - `destroy`    Soft-delete (unpin). 204.
 *   - `refreshAll` SSE bulk: rate-limited, ejecuta `ReplayService::replayBulk`
 *                  (parallelism + chunk en E3) y emite un frame
 *                  `widget_refreshed` por cada resultado + un `done` final.
 *
 * Política 404-no-403: cualquier slug/widget ajeno o soft-deleted → 404.
 *
 * Rate limit: `chatbot.dashboard.replay.rate_limit_per_user_per_minute`
 * (default 60) aplica SÓLO a `refresh` + `refreshAll`. El CRUD (pin/unpin/
 * move) no entra al throttle: el coste real está en re-ejecutar tools, no
 * en escribir filas. Bulk cuenta como 1 hit (la concurrency cap del E3
 * protege internamente).
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

        // Enforcement aguas arriba (paridad con E3): pinnable() === true Y
        // confirmation() === Auto. Tools que mutan jamás llegan al
        // dashboard, aunque overrideen pinnable() por descuido. Mantenemos
        // el pre-check inline para preservar el shape histórico del 422
        // (el cliente JS asume `errors['source.tool']`); `PinService` lo
        // re-chequea como defense-in-depth.
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

        // El descriptor `block` que pasa a `PinService` es el mismo shape
        // que la tool emite en `ToolResult::blocks`: `type` + `data` +
        // opcionales `id` (audit) y `ordinal` (replay matching de tools
        // multi-block, v2.1.2 #27). El cliente JS suministra los dos
        // últimos cuando los conoce; ausentes, el replay cae a ordinal 0.
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
     * Mapea las categorías de `PinException` al shape histórico de JSON 422
     * del controller. El service propaga la excepción con
     * `category` + `context`; aquí preservamos los mensajes idénticos al
     * controller v2.1.x para que tests y clientes JS no detecten cambio.
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

        // Sólo las claves PRESENTES en el request entran al diff — semántica
        // PATCH idéntica al controller v2.1.x: ausentes preservan, `title:
        // null` limpia. `WidgetCrudService::update()` normaliza position y
        // valida `refresh_policy` contra el enum.
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
     * Mirror del rate limiter de `/chatbot/stream` (E09): clave por usuario,
     * ventana 60 s, max configurable. Devuelve Response 429 si excedido o
     * null si dentro del límite (y registra hit). Aplica SÓLO a refresh +
     * refreshAll — el CRUD no entra al throttle (decisión §4.10 / E4).
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
