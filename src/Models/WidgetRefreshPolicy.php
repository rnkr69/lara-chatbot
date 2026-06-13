<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Refresh policy of a `chatbot_dashboard_widgets` row (v2.0 / E2).
 *
 *  - `OnOpen` (default): the `DashboardApp` (E5) runs replay when opening the
 *    dashboard. Reduces load vs. polling and delivers "today's data" without
 *    user intervention.
 *  - `Manual`: never auto-replays; only when the user clicks "↻" in the
 *    widget header. Useful for expensive or noisy queries.
 *  - `Never`: the snapshot stays frozen. Useful for historical snapshots
 *    ("Q1 close") that lose meaning if the numbers are updated.
 */
enum WidgetRefreshPolicy: string
{
    case OnOpen = 'on_open';
    case Manual = 'manual';
    case Never  = 'never';
}
