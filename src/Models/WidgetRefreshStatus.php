<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Status of a widget's last replay (v2.0 / E2). Set by `ReplayService`
 * (E3) after each execution; on pinning it is initialized to `Fresh` with
 * `last_refreshed_at = now()` because the freshly created snapshot is
 * already fresh by definition.
 *
 *  - `Fresh`: the last replay returned a block of the same type as the
 *    snapshot; the data is up to date.
 *  - `Stale`: the replay worked but the tool returned a block of a
 *    different type (e.g. a table mutated into a text). The previous
 *    snapshot stays visible with a ⚠️ badge — the UI suggests "re-pin from
 *    the chat".
 *  - `Error`: runtime/validation error during the replay. Previous
 *    snapshot visible + detail in `last_refresh_error`.
 *  - `Unauthorized`: the `permission → scope → tenant → ownership` cascade
 *    failed. The previous snapshot is kept but new unauthorized data is
 *    NEVER delivered.
 *  - `SourceMissing`: the original tool no longer exists in the registry
 *    (host deleted it or renamed it). Snapshot frozen; UI invites to unpin.
 */
enum WidgetRefreshStatus: string
{
    case Fresh         = 'fresh';
    case Stale         = 'stale';
    case Error         = 'error';
    case Unauthorized  = 'unauthorized';
    case SourceMissing = 'source_missing';
}
