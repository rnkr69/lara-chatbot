<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload of `POST /chatbot/dashboards/{slug}/widgets` (v2.0 / E4).
 *
 * The client sends exactly what came in the SSE `block` frame from E1
 * (orchestrator):
 *
 *   {
 *     block_id:      uuid,                                 -- audit ref
 *     block_type:    'table'|'kpi'|'chart'|'card'|'list',
 *     block_ordinal: int >= 0,                             -- v2.1.2 (#27):
 *                                                             0-based position of the
 *                                                             block among those of its
 *                                                             type in the ToolResult.
 *                                                             Replay uses it to
 *                                                             re-locate THIS block
 *                                                             in multi-block tools.
 *     snapshot:   { data: object, captured_at?, byte_size? },
 *     source: {
 *       tool:               string,        -- name of the tool
 *       args:               object,        -- args it was invoked with
 *       page_context_keys?: string[],      -- context keys that the tool
 *                                              declared as context-sensitive
 *     },
 *     suggested_title?: string,
 *     page_context?:    object,            -- CURRENT page context;
 *                                              the server filters by
 *                                              `source.page_context_keys`
 *     position?: { x:int, y:int, w:int, h:int },
 *   }
 *
 * Upstream enforcement (not here): `ToolRegistry::get(source.tool)`
 * exists, `pinnable() === true`, `confirmation() === Auto`. If any
 * fails, the controller returns 422 with an explicit error (`tool_unpinnable`).
 *
 * Also not enforced here: `chatbot.dashboard.max_widgets_per_dashboard`
 * (default 50) — the controller counts the dashboard's widgets after resolving it.
 */
class PinWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'block_id'                  => ['nullable', 'string', 'max:64'],
            'block_type'                => ['required', 'string', 'max:32'],
            // v2.1.2 (#27) — stable half of the replay descriptor. `nullable`:
            // a v2.1.1 client does not send it; replay falls back to ordinal 0 (first
            // block of the widget's type) — degraded but no worse than 2.1.1.
            'block_ordinal'             => ['nullable', 'integer', 'min:0'],
            'snapshot'                  => ['required', 'array'],
            // `present, array` (not `required`): a legitimate block can emit
            // `data: []` (table with no rows, empty list). `required` rejects
            // empty arrays in Laravel — we use `present` to force the
            // key but accept `[]` as a valid value.
            'snapshot.data'             => ['present', 'array'],
            'source'                    => ['required', 'array'],
            'source.tool'               => ['required', 'string', 'max:255'],
            'source.args'               => ['nullable', 'array'],
            'source.page_context_keys'  => ['nullable', 'array'],
            'source.page_context_keys.*'=> ['string', 'max:120'],
            'suggested_title'           => ['nullable', 'string', 'max:180'],
            'page_context'              => ['nullable', 'array'],
            'position'                  => ['nullable', 'array'],
            'position.x'                => ['nullable', 'integer', 'min:0', 'max:11'],
            'position.y'                => ['nullable', 'integer', 'min:0'],
            'position.w'                => ['nullable', 'integer', 'min:1', 'max:12'],
            'position.h'                => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }
}
