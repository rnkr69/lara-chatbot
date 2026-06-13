<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization;

/**
 * Levels of data scope accessible to a tool (ROADMAP §2.2).
 *
 * - Self  — only the invoking user's own records.
 * - Team  — own + the team members' records (manager → team). The host
 *           implements the concrete resolution via `ScopeResolver`.
 * - All   — no ownership restriction (typical of admin roles).
 *
 * The case name is PascalCase (Self/Team/All); the backed string value
 * (`'self'|'team'|'all'`) is what travels through config and the tools'
 * public API.
 */
enum AccessScope: string
{
    case Self = 'self';
    case Team = 'team';
    case All  = 'all';
}
