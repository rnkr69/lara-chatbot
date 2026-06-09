<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

/**
 * Niveles de scope de datos accesibles a una tool (ROADMAP §2.2).
 *
 * - Self  — sólo registros propios del usuario invocador.
 * - Team  — propios + los de los miembros del equipo (manager → equipo). El
 *           host implementa la resolución concreta vía `ScopeResolver`.
 * - All   — sin restricción de propiedad (típico de roles admin).
 *
 * El nombre del case es PascalCase (Self/Team/All); el valor backed string
 * (`'self'|'team'|'all'`) es el que viaja por config y por la API pública
 * de las tools.
 */
enum AccessScope: string
{
    case Self = 'self';
    case Team = 'team';
    case All  = 'all';
}
