<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Models;

/**
 * Política de refresco de un `chatbot_dashboard_widgets` row (v2.0 / E2).
 *
 *  - `OnOpen` (default): el `DashboardApp` (E5) ejecuta replay al abrir el
 *    dashboard. Reduce carga vs. polling y entrega "datos del día" sin
 *    intervención del usuario.
 *  - `Manual`: nunca replay automático; sólo cuando el usuario pulsa "↻" en
 *    el header del widget. Útil para queries caras o ruidosas.
 *  - `Never`: el snapshot queda congelado. Útil para fotografías históricas
 *    ("cierre del Q1") que pierden sentido si los números se actualizan.
 */
enum WidgetRefreshPolicy: string
{
    case OnOpen = 'on_open';
    case Manual = 'manual';
    case Never  = 'never';
}
