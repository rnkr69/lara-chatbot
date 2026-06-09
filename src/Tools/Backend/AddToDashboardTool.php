<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Backend;

use Rnkr69\LaraChatbot\Dashboard\PinException;
use Rnkr69\LaraChatbot\Dashboard\PinService;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Tools\BaseBackendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Throwable;

/**
 * v2.2 — Tool conversacional que pinea un block al dashboard del usuario
 * SIN tener que pasar por el flujo manual (chat → render → hover → 📌 →
 * modal → pin). El LLM la invoca cuando el usuario pide algo del tipo
 * "añade mis KPIs al panel" o "pinea el listado de misiones".
 *
 * Orquesta tres pasos:
 *
 *   1. Resuelve el tool de origen (`source_tool`) y el dashboard target
 *      (slug o default del user).
 *   2. Ejecuta el tool de origen — la cascada de `BaseBackendTool::execute()`
 *      (permission → scope → tenant → validation) aplica al source_tool;
 *      esta wrapper no abre puertas que el LLM no tuviera ya.
 *   3. Selecciona el bloque indicado (`block_type` + `block_ordinal`) del
 *      resultado y delega en `PinService::pin` para persistirlo.
 *
 * Errores se devuelven como `ToolResult::error(category, message)` con la
 * misma categoría que el doc 2.1.3 promete (`tool_not_found`, `not_pinnable`,
 * `unauthorized`, `out_of_scope`, `dashboard_not_found`, `no_dashboard`,
 * `cap_reached`, `source_args_invalid`, `source_runtime`, `no_block`,
 * `ordinal_out_of_range`). Los mensajes vienen de `chatbot::chatbot.add_to_dashboard.errors.*`
 * y el host los puede traducir publicando lang.
 *
 * No emite confirmation banner: la propia acción "añade X" es el consent
 * (`confirmation = Auto`); pedir un banner extra sería redundante (mismo
 * principio que el fix v2.1.1 #L2 de un host de prueba).
 *
 * `pinnable = false` aquí: la salida de esta tool es una tarjeta de
 * confirmación, no contenido data-driven que tenga sentido pinear (¡recursión!).
 */
class AddToDashboardTool extends BaseBackendTool
{
    public function __construct(
        protected ToolRegistry $registry,
        protected PinService $pinService,
    ) {}

    public function name(): string
    {
        return 'add_to_dashboard';
    }

    public function description(): string
    {
        return 'Add a content block from another tool to the user\'s personal dashboard. INVOKE this when the user asks "add X to my dashboard", "pin Y", "save these KPIs to my dashboard", or any variant — even if the block has not been generated in the conversation yet. Arguments: `source_tool` (required, the name of the backend tool that produces the block to add — must be `pinnable` and `confirmation=Auto`); `source_args` (optional, args for the source tool); `block_type` (optional, when the source tool emits multiple block types — e.g. fleet_kpis emits kpi+chart — pick one); `block_ordinal` (optional, 0-based index among blocks of the same type, useful for multi-block tools); `dashboard_slug` (optional, defaults to the user\'s default dashboard); `title` (optional, suggested widget title). Returns `success({widget_id, dashboard_slug, title})` on success, or `error({category, message})` with a user-readable reason on failure. The LLM MUST relay the error message verbatim — the messages already explain WHY in plain language.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source_tool'    => ['type' => 'string'],
                'source_args'    => ['type' => 'object'],
                'block_type'     => ['type' => 'string', 'description' => 'For multi-block tools, pick one type (e.g. "kpi" or "chart" for fleet_kpis).'],
                'block_ordinal'  => ['type' => 'integer', 'description' => '0-based index among blocks of the same type. Default 0.'],
                'dashboard_slug' => ['type' => 'string'],
                'title'          => ['type' => 'string'],
            ],
            'required' => ['source_tool'],
        ];
    }

    public function permissions(): array
    {
        return [];
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }

    public function pinnable(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $toolName = isset($args['source_tool']) && is_string($args['source_tool']) ? trim($args['source_tool']) : '';
        $sourceArgs = is_array($args['source_args'] ?? null) ? $args['source_args'] : [];
        $blockType = isset($args['block_type']) && is_string($args['block_type']) ? trim($args['block_type']) : null;
        $blockOrdinal = isset($args['block_ordinal']) && is_int($args['block_ordinal']) && $args['block_ordinal'] >= 0
            ? $args['block_ordinal']
            : 0;
        $suggestedTitle = isset($args['title']) && is_string($args['title']) && $args['title'] !== ''
            ? $args['title']
            : null;
        $dashboardSlug = isset($args['dashboard_slug']) && is_string($args['dashboard_slug']) && $args['dashboard_slug'] !== ''
            ? $args['dashboard_slug']
            : null;

        // 1. Source tool resolution.
        $sourceTool = $this->registry->get($toolName);
        if ($sourceTool === null) {
            return ToolResult::error(
                'tool_not_found',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.tool_not_found', [
                    'tool' => $toolName,
                    'list' => $this->listPinnableToolsFor($ctx),
                ]),
            );
        }

        // 2. Pinnable enforcement (defense-in-depth — PinService re-checks).
        if (! $sourceTool->pinnable() || $sourceTool->confirmation() !== ConfirmationLevel::Auto) {
            return ToolResult::error(
                'not_pinnable',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.not_pinnable', ['tool' => $toolName]),
            );
        }

        // 3. Dashboard resolution.
        $dashboard = $this->resolveDashboard($ctx, $dashboardSlug);
        if ($dashboard === null) {
            return $dashboardSlug !== null
                ? ToolResult::error(
                    'dashboard_not_found',
                    (string) __('chatbot::chatbot.add_to_dashboard.errors.dashboard_not_found', [
                        'slug' => $dashboardSlug,
                        'list' => $this->listDashboardSlugsFor($ctx),
                    ]),
                )
                : ToolResult::error(
                    'no_dashboard',
                    (string) __('chatbot::chatbot.add_to_dashboard.errors.no_dashboard'),
                );
        }

        // 4. Widget cap pre-check (paridad con controller). PinService lo
        //    re-chequea para cubrir la carrera de "otra pestaña pinó justo
        //    antes". En este turno se evita ejecutar el source_tool si ya
        //    sabemos que no va a caber.
        $cap = (int) config('chatbot.dashboard.max_widgets_per_dashboard', 50);
        $current = $dashboard->widgets()->count();
        if ($cap > 0 && $current >= $cap) {
            return ToolResult::error(
                'cap_reached',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.cap_reached', [
                    'name'    => $dashboard->name,
                    'current' => $current,
                    'max'     => $cap,
                ]),
            );
        }

        // 5. Ejecuta el source tool. `execute()` aplica el cascade del
        //    paquete (validation → permission → scope → tenant → handle).
        try {
            $sourceResult = $sourceTool->execute($sourceArgs, $ctx);
        } catch (Throwable $e) {
            return ToolResult::error(
                'source_runtime',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.source_runtime', [
                    'tool'   => $toolName,
                    'detail' => $e->getMessage(),
                ]),
            );
        }

        if ($sourceResult->isError()) {
            return $this->mapSourceError($sourceResult, $toolName);
        }

        // 6. Block selection. Filter by `block_type` if given; otherwise
        //    consider all blocks. Within candidates, pick by ordinal.
        $candidates = [];
        foreach ($sourceResult->blocks as $i => $block) {
            if (! is_array($block) || ! isset($block['type']) || ! is_string($block['type'])) {
                continue;
            }
            if ($blockType !== null && $block['type'] !== $blockType) {
                continue;
            }
            $candidates[] = $block;
        }

        if ($candidates === []) {
            return ToolResult::error(
                'no_block',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.no_block', ['tool' => $toolName]),
            );
        }

        if (! array_key_exists($blockOrdinal, $candidates)) {
            return ToolResult::error(
                'ordinal_out_of_range',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.ordinal_out_of_range', [
                    'tool'    => $toolName,
                    'count'   => count($candidates),
                    'type'    => $blockType ?? '',
                    'ordinal' => $blockOrdinal + 1,
                ]),
            );
        }

        $selected = $candidates[$blockOrdinal];
        $selectedBlockType = (string) ($selected['type'] ?? '');
        $selectedBlockData = is_array($selected['data'] ?? null) ? $selected['data'] : [];
        $selectedBlockId   = isset($selected['id']) && is_string($selected['id']) ? $selected['id'] : null;

        // 7. Pin via PinService. Same descriptor shape the HTTP path uses —
        //    `block_ordinal` is the position within candidates (the N-th
        //    block of its type from the source tool), which the replay
        //    engine re-localises identically.
        $block = [
            'type'    => $selectedBlockType,
            'data'    => $selectedBlockData,
            'ordinal' => $blockOrdinal,
        ];
        if ($selectedBlockId !== null) {
            $block['id'] = $selectedBlockId;
        }

        try {
            $widget = $this->pinService->pin(
                dashboard: $dashboard,
                sourceTool: $sourceTool,
                sourceArgs: $sourceArgs,
                block: $block,
                suggestedTitle: $suggestedTitle,
                pageContext: $ctx->pageContext,
                pageContextKeys: array_values(array_filter(array_keys($ctx->pageContext), 'is_string')),
            );
        } catch (PinException $e) {
            // Mapeo defense-in-depth: el pre-check ya cubre el caso normal,
            // pero si una pestaña concurrente pinó justo antes y excede el
            // cap, lo reportamos con el mismo wording que el pre-check.
            return $this->mapPinException($e, $dashboard);
        }

        // 8. Success card. El LLM la repite verbatim al usuario.
        $url = $this->dashboardUrl($dashboard);
        $widgetTitle = $widget->title ?? ($suggestedTitle ?? $selectedBlockType);

        return ToolResult::success(
            data: [
                'widget_id'      => $widget->id,
                'dashboard_slug' => $dashboard->slug,
                'title'          => $widgetTitle,
                'dashboard_url'  => $url,
            ],
            blocks: [[
                'type' => 'card',
                'data' => [
                    'title'       => (string) __('chatbot::chatbot.add_to_dashboard.success.card_title'),
                    'description' => (string) __('chatbot::chatbot.add_to_dashboard.success.card_description', [
                        'title'     => $widgetTitle,
                        'dashboard' => $dashboard->name,
                        'url'       => $url,
                    ]),
                ],
                // v2.2.1 (PR-B) — el bundle del dashboard escucha
                // `chatbot:dashboard-mutation` para refrescar sin F5 cuando la
                // mutación viene del chat. El orquestador propaga `meta` verbatim.
                'meta' => [
                    'side_effects' => [
                        'type'           => 'widget_added',
                        'dashboard_slug' => $dashboard->slug,
                        'widget_id'      => (int) $widget->id,
                    ],
                ],
            ]],
        );
    }

    /**
     * Resuelve el dashboard target. Si el LLM pasó `dashboard_slug`, busca
     * por slug; si no, el `is_default` del user. Devuelve `null` si no
     * existe ninguno apto — el caller distingue `dashboard_not_found` de
     * `no_dashboard` por la presencia del slug.
     */
    protected function resolveDashboard(ToolContext $ctx, ?string $slug): ?Dashboard
    {
        $query = Dashboard::query()->forUser($ctx->user);

        if ($slug !== null) {
            return $query->where('slug', $slug)->first();
        }

        return $query->default()->first();
    }

    /**
     * Lista los slugs de los dashboards del usuario (para el mensaje de
     * `dashboard_not_found`). Truncado a un máximo razonable para no
     * inundar el mensaje cuando el user tiene muchos.
     */
    protected function listDashboardSlugsFor(ToolContext $ctx): string
    {
        $slugs = Dashboard::query()
            ->forUser($ctx->user)
            ->orderBy('is_default', 'desc')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->pluck('slug')
            ->all();

        if ($slugs === []) {
            return '—';
        }

        return implode(', ', array_map(fn (string $s): string => "'{$s}'", $slugs));
    }

    /**
     * Lista los nombres de las tools pinnable+Auto disponibles para el user
     * actual (las que el ToolRegistry sabe que el user puede invocar y que
     * son pineables). Usado en el mensaje de `tool_not_found` — pasarle al
     * LLM las alternativas ahorra una iteración del loop "no existe" →
     * "vuelve a llamar con otro nombre".
     */
    protected function listPinnableToolsFor(ToolContext $ctx): string
    {
        $available = [];

        foreach ($this->registry->forUser($ctx->user) as $name => $tool) {
            if ($tool->pinnable() && $tool->confirmation() === ConfirmationLevel::Auto) {
                $available[] = $name;
            }
        }

        if ($available === []) {
            return '—';
        }

        sort($available);

        return implode(', ', array_slice($available, 0, 20));
    }

    /**
     * Mapea categorías del `ToolResult::error` del source_tool al wrapper
     * de este tool. Los nombres deliberadamente difieren para que el LLM
     * pueda razonar sobre cuál de los dos niveles falló (la cascada del
     * tool de origen vs. el pin propiamente).
     */
    protected function mapSourceError(ToolResult $sourceResult, string $toolName): ToolResult
    {
        $category = $sourceResult->errorCategory ?? 'source_runtime';
        $detail   = $sourceResult->errorMessage ?? '';

        return match ($category) {
            'validation' => ToolResult::error(
                'source_args_invalid',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.source_args_invalid', [
                    'tool'   => $toolName,
                    'detail' => $detail,
                ]),
            ),
            'unauthorized' => ToolResult::error(
                'unauthorized',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.unauthorized'),
            ),
            'out_of_scope' => ToolResult::error(
                'out_of_scope',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.out_of_scope'),
            ),
            default => ToolResult::error(
                'source_runtime',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.source_runtime', [
                    'tool'   => $toolName,
                    'detail' => $detail,
                ]),
            ),
        };
    }

    /**
     * Reduce un `PinException` lanzado por `PinService` a `ToolResult::error`.
     * El pre-check del cap aquí en el tool cubre el caso normal; este map
     * cubre la carrera concurrente (otra pestaña pinó justo antes de
     * persistir).
     */
    protected function mapPinException(PinException $e, Dashboard $dashboard): ToolResult
    {
        return match ($e->category) {
            'cap_reached' => ToolResult::error(
                'cap_reached',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.cap_reached', [
                    'name'    => $dashboard->name,
                    'current' => (int) ($e->context['current'] ?? 0),
                    'max'     => (int) ($e->context['cap'] ?? 0),
                ]),
            ),
            'not_pinnable' => ToolResult::error(
                'not_pinnable',
                (string) __('chatbot::chatbot.add_to_dashboard.errors.not_pinnable', [
                    'tool' => (string) ($e->context['tool'] ?? ''),
                ]),
            ),
            default => ToolResult::error('source_runtime', $e->getMessage()),
        };
    }

    /**
     * URL del dashboard. La ruta nombrada `chatbot.dashboard` no acepta
     * slug (es un único view que renderiza el dashboard del user con la
     * sidebar para cambiar entre paneles); se incluye el slug como query
     * param `?dashboard=` para que `DashboardController::resolveDefaultSlug`
     * lo lea y el bundle JS auto-seleccione ese panel al cargar. v2.2.1:
     * antes emitíamos `?slug=` por error — el controlador lo ignoraba y la
     * "Open dashboard" del card aterrizaba siempre en el default del user.
     */
    protected function dashboardUrl(Dashboard $dashboard): string
    {
        try {
            $base = route('chatbot.dashboard');
        } catch (Throwable $e) {
            $prefix = (string) config('chatbot.route.prefix', 'chatbot');

            return '/' . trim($prefix, '/') . '/dashboard';
        }

        return $base . '?dashboard=' . rawurlencode($dashboard->slug);
    }
}
