<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el payload de `POST /chatbot/dashboards/{slug}/widgets` (v2.0 / E4).
 *
 * El cliente envía exactamente lo que vino en el SSE `block` frame del E1
 * (orquestador):
 *
 *   {
 *     block_id:      uuid,                                 -- audit ref
 *     block_type:    'table'|'kpi'|'chart'|'card'|'list',
 *     block_ordinal: int >= 0,                             -- v2.1.2 (#27):
 *                                                             posición 0-based del
 *                                                             bloque entre los de su
 *                                                             tipo en el ToolResult.
 *                                                             El replay lo usa para
 *                                                             re-localizar ESTE bloque
 *                                                             en tools multi-bloque.
 *     snapshot:   { data: object, captured_at?, byte_size? },
 *     source: {
 *       tool:               string,        -- nombre de la tool
 *       args:               object,        -- args con los que se invocó
 *       page_context_keys?: string[],      -- claves del context que el tool
 *                                              declaró como context-sensitive
 *     },
 *     suggested_title?: string,
 *     page_context?:    object,            -- contexto VIGENTE de la página;
 *                                              el server filtra por
 *                                              `source.page_context_keys`
 *     position?: { x:int, y:int, w:int, h:int },
 *   }
 *
 * Enforcement aguas arriba (no aquí): `ToolRegistry::get(source.tool)`
 * existe, `pinnable() === true`, `confirmation() === Auto`. Si alguno
 * falla, el controller devuelve 422 con un error explícito (`tool_unpinnable`).
 *
 * Tampoco se enforce aquí: `chatbot.dashboard.max_widgets_per_dashboard`
 * (default 50) — el controller cuenta widgets del dashboard tras resolverlo.
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
            // v2.1.2 (#27) — mitad estable del descriptor de replay. `nullable`:
            // un cliente v2.1.1 no lo envía; el replay cae a ordinal 0 (primer
            // bloque del tipo del widget) — degradado pero no peor que 2.1.1.
            'block_ordinal'             => ['nullable', 'integer', 'min:0'],
            'snapshot'                  => ['required', 'array'],
            // `present, array` (no `required`): un block legítimo puede emitir
            // `data: []` (tabla sin filas, lista vacía). `required` rechaza
            // arrays vacíos en Laravel — usamos `present` para forzar la
            // clave pero aceptar `[]` como valor válido.
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
