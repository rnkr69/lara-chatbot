<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

/**
 * Confirmation levels a tool can require before executing
 * (ROADMAP §5/E16).
 *
 * - Auto    — the tool executes without asking the user for confirmation.
 * - Confirm — the tool asks the user for confirmation before executing.
 *             For backend tools it is deferred to v2 (see §7 PROGRESS.md);
 *             for frontend tools (E11/E16) it is materialized with the
 *             `chatbot_pending_actions` table.
 * - Manual  — the tool never executes automatically; the user must
 *             trigger it explicitly from the chat. Only applies to
 *             frontend tools in v1.
 *
 * The enum is introduced in E06 because the `BackendTool::confirmation()`
 * interface needs it; backend tools in v1 must return `Auto` (any other
 * value is rejected by the orchestrator in E08 until v2).
 */
enum ConfirmationLevel: string
{
    case Auto    = 'auto';
    case Confirm = 'confirm';
    case Manual  = 'manual';
}
