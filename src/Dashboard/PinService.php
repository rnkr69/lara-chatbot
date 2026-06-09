<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Rnkr69\LaraChatbot\Models\Dashboard;
use Rnkr69\LaraChatbot\Models\DashboardWidget;
use Rnkr69\LaraChatbot\Models\WidgetRefreshPolicy;
use Rnkr69\LaraChatbot\Models\WidgetRefreshStatus;
use Rnkr69\LaraChatbot\Services\PageContextSanitizer;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\Contracts\BackendTool;

/**
 * v2.2 — Pinea un block como `DashboardWidget`. Extrae toda la lógica de
 * orquestación que vivía inline en `ApiDashboardWidgetController::store`:
 * defensa pinnable, cap de widgets, truncado de snapshot, sanitización +
 * filtrado del page_context al subset declarado por la tool, posición
 * default por `block_type`, source signature, persist + touch del dashboard.
 *
 * Dos callers comparten el servicio:
 *
 *   1. **Controller HTTP** (`POST /chatbot/dashboards/{slug}/widgets`).
 *      Camino histórico — el usuario hace click 📌 sobre un block en el chat;
 *      el cliente JS ya tiene el snapshot y manda `{block_id, snapshot,
 *      source, ...}` al servidor. El controller resuelve la tool + dashboard
 *      y llama a este servicio.
 *   2. **`AddToDashboardTool`** (auto-pin desde el chat, v2.2 PR-A). El LLM
 *      invoca la tool con `{source_tool, source_args, dashboard_slug?, ...}`;
 *      la tool ejecuta el source_tool, selecciona el block adecuado y llama
 *      a este servicio.
 *
 * Errores de dominio se propagan como `PinException` con categoría
 * (`cap_reached`, `not_pinnable`). Cada caller mapea a su forma:
 * controller → JSON 422, tool → `ToolResult::error(...)`.
 *
 * Sin cambios de contrato HTTP: el shape persistido del widget (incluido el
 * descriptor `source`) es idéntico al de v2.1.x para preservar replay
 * compatibility.
 */
class PinService
{
    public function __construct(
        protected PageContextSanitizer $sanitizer,
    ) {}

    /**
     * @param array<string, mixed> $sourceArgs
     * @param array{type:string, data?: array<string, mixed>, id?: string, ordinal?: int} $block
     *                                                                             el block seleccionado a persistir.
     *                                                                             `data` es el cuerpo del snapshot; `id`/`ordinal`
     *                                                                             son opcionales (audit + replay matching).
     * @param array<string, mixed>|null $pageContext     page_context RAW del request (sin sanear, sin filtrar);
     *                                                   este servicio aplica ambos pasos.
     * @param array<int, string>|null   $pageContextKeys keys declaradas por el source tool — sólo este subset
     *                                                   del page_context se persiste en `source.page_context_snapshot`.
     * @param array<string, mixed>|null $position        position cliente-provided (clamped); `null` = posición default
     *                                                   `(x:0, y:9999)` con `w/h` heurísticos por block_type.
     *
     * @throws PinException cap_reached | not_pinnable
     */
    public function pin(
        Dashboard $dashboard,
        BackendTool $sourceTool,
        array $sourceArgs,
        array $block,
        ?string $suggestedTitle = null,
        ?array $pageContext = null,
        ?array $pageContextKeys = null,
        ?array $position = null,
    ): DashboardWidget {
        // 1. Defense-in-depth (paridad con controller l.103–113). Aunque el
        //    caller suela pre-chequear, el servicio no confía en él.
        if (! $sourceTool->pinnable() || $sourceTool->confirmation() !== ConfirmationLevel::Auto) {
            throw PinException::notPinnable($sourceTool->name());
        }

        // 2. Widget cap (paridad con controller l.70–83).
        $cap = (int) config('chatbot.dashboard.max_widgets_per_dashboard', 50);
        $current = $dashboard->widgets()->count();

        if ($cap > 0 && $current >= $cap) {
            throw PinException::capReached($cap, $current);
        }

        // 3. Atributos derivados.
        $blockType = (string) ($block['type'] ?? '');
        $blockData = is_array($block['data'] ?? null) ? $block['data'] : [];
        $snapshot  = $this->prepareSnapshot(['data' => $blockData]);

        $keys = is_array($pageContextKeys) ? $pageContextKeys : [];
        $pageContextSnapshot = $this->capturePageContextSnapshot(
            is_array($pageContext) ? $pageContext : [],
            $keys,
        );

        $toolName = $sourceTool->name();
        $persistedSource = [
            'tool'                  => $toolName,
            'args'                  => $sourceArgs,
            'page_context_keys'     => array_values(array_filter($keys, 'is_string')),
            'page_context_snapshot' => $pageContextSnapshot,
        ];

        $blockId = $block['id'] ?? null;
        if (is_string($blockId) && $blockId !== '') {
            $persistedSource['block_id'] = $blockId;
        }

        $blockOrdinal = $block['ordinal'] ?? null;
        if (is_int($blockOrdinal) && $blockOrdinal >= 0) {
            $persistedSource['block_ordinal'] = $blockOrdinal;
        }

        $resolvedPosition = WidgetPositionNormalizer::normalize($position, $blockType);
        $orderIndex      = ((int) $dashboard->widgets()->max('order_index')) + 1;
        $defaultPolicy   = (string) config('chatbot.dashboard.default_refresh_policy', 'on_open');
        $policy          = WidgetRefreshPolicy::tryFrom($defaultPolicy) ?? WidgetRefreshPolicy::OnOpen;

        $widget = DashboardWidget::create([
            'dashboard_id'        => $dashboard->id,
            'position'            => $resolvedPosition,
            'block_type'          => $blockType,
            'title'               => $suggestedTitle,
            'snapshot'            => $snapshot,
            'source'              => $persistedSource,
            'source_signature'    => SourceSignature::for($toolName, $sourceArgs),
            'refresh_policy'      => $policy,
            'last_refreshed_at'   => Carbon::now(),
            'last_refresh_status' => WidgetRefreshStatus::Fresh,
            'last_refresh_error'  => null,
            'order_index'         => $orderIndex,
        ]);

        // Touch the dashboard so `updated_at` reflects "last pin time" — la
        // sidebar usa este orden para "recently used" panels.
        $dashboard->touch();

        return $widget;
    }

    /**
     * Hard cap del snapshot persistido (`chatbot.dashboard.snapshot_max_bytes`,
     * default 256 KB). Si el JSON de `data` excede el cap, conservamos sólo
     * `data.head` (primeras filas si es array list — null en otro caso) +
     * marker `truncated: true`. El replay (E3) re-ejecuta el tool al abrir
     * y reemplaza el snapshot con datos frescos completos (≤ cap también);
     * el truncado del pin sólo cubre el caso patológico (datasets enormes
     * pre-computados antes del primer replay).
     *
     * @param  array<string, mixed>  $rawSnapshot
     * @return array<string, mixed>
     */
    protected function prepareSnapshot(array $rawSnapshot): array
    {
        $cap = (int) config('chatbot.dashboard.snapshot_max_bytes', 256 * 1024);
        $data = is_array($rawSnapshot['data'] ?? null) ? $rawSnapshot['data'] : [];

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byteSize = is_string($encoded) ? strlen($encoded) : 0;

        if ($cap > 0 && $byteSize > $cap) {
            Log::info(sprintf(
                '[chatbot] dashboard widget snapshot truncated: %d bytes > cap %d. data.head preserved.',
                $byteSize,
                $cap,
            ));

            $head = null;
            if (array_is_list($data) && $data !== []) {
                $head = array_slice($data, 0, 20);
            }

            return [
                'data'        => ['truncated' => true, 'head' => $head, 'original_byte_size' => $byteSize],
                'captured_at' => Carbon::now()->toIso8601String(),
                'byte_size'   => $byteSize,
                'truncated'   => true,
            ];
        }

        return [
            'data'        => $data,
            'captured_at' => Carbon::now()->toIso8601String(),
            'byte_size'   => $byteSize,
        ];
    }

    /**
     * Filtra el `page_context` actual del request a las claves declaradas
     * por el tool en `source.page_context_keys`. Aplica:
     *
     *   1. `PageContextSanitizer::sanitize()` (drop closures/objects/null/
     *      recursos/floats no finitos — la misma defensa de `/stream`).
     *   2. Filtrado por keys: sólo las claves listadas pasan al snapshot.
     *   3. Cap binario de `chatbot.limits.page_context_kb` (default 16 KB):
     *      si el JSON resultante excede, se descarta entero + log info.
     *
     * Devuelve `[]` cuando no hay context, las keys están vacías, o la
     * sanitización purga todo. Coherente con el comportamiento de
     * `ChatController::sanitizePageContext`.
     *
     * @param  array<string, mixed>  $rawContext
     * @param  array<int, string>    $keys
     * @return array<string, mixed>
     */
    protected function capturePageContextSnapshot(array $rawContext, array $keys): array
    {
        if ($rawContext === [] || $keys === []) {
            return [];
        }

        $sanitized = $this->sanitizer->sanitize($rawContext);
        if ($sanitized === []) {
            return [];
        }

        $stringKeys = array_filter($keys, 'is_string');
        $filtered   = [];
        foreach ($stringKeys as $key) {
            if (array_key_exists($key, $sanitized)) {
                $filtered[$key] = $sanitized[$key];
            }
        }

        if ($filtered === []) {
            return [];
        }

        $limitKb = (int) config('chatbot.limits.page_context_kb', 16);
        $limit   = max(1, $limitKb) * 1024;

        $encoded = json_encode($filtered);
        if (! is_string($encoded) || strlen($encoded) > $limit) {
            Log::info(sprintf(
                '[chatbot] dashboard widget page_context_snapshot descartado por exceder %d KB tras filtrar.',
                $limitKb,
            ));

            return [];
        }

        return $filtered;
    }

}
