<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Estado del último replay de un widget (v2.0 / E2). Lo setea
 * `ReplayService` (E3) tras cada ejecución; al pinear se inicializa a
 * `Fresh` con `last_refreshed_at = now()` porque el snapshot recién creado
 * ya es fresco por definición.
 *
 *  - `Fresh`: el último replay devolvió un block del mismo type que el
 *    snapshot; los datos están al día.
 *  - `Stale`: el replay funcionó pero el tool devolvió un block de otro
 *    tipo (p. ej. una table mutó a un text). El snapshot anterior queda
 *    visible con badge ⚠️ — la UI sugiere "repinear desde el chat".
 *  - `Error`: error de runtime/validation durante el replay. Snapshot
 *    anterior visible + detalle en `last_refresh_error`.
 *  - `Unauthorized`: cascada `permission → scope → tenant → ownership`
 *    falló. Snapshot anterior se mantiene pero NUNCA se entregan datos
 *    nuevos no autorizados.
 *  - `SourceMissing`: la tool original ya no existe en el registry (host
 *    la borró o cambió el nombre). Snapshot frozen; UI invita a unpin.
 */
enum WidgetRefreshStatus: string
{
    case Fresh         = 'fresh';
    case Stale         = 'stale';
    case Error         = 'error';
    case Unauthorized  = 'unauthorized';
    case SourceMissing = 'source_missing';
}
