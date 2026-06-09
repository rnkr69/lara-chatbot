<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools;

/**
 * Niveles de confirmación que una tool puede requerir antes de ejecutarse
 * (ROADMAP §5/E16).
 *
 * - Auto    — la tool se ejecuta sin pedir confirmación al usuario.
 * - Confirm — la tool pide confirmación al usuario antes de ejecutarse.
 *             En backend tools queda diferido a v2 (ver §7 PROGRESS.md);
 *             en frontend tools (E11/E16) se materializa con la tabla
 *             `chatbot_pending_actions`.
 * - Manual  — la tool nunca se ejecuta automáticamente; el usuario debe
 *             dispararla explícitamente desde el chat. Sólo aplica a
 *             frontend tools en v1.
 *
 * El enum se introduce en E06 porque la interfaz `BackendTool::confirmation()`
 * lo necesita; backend tools en v1 deben devolver `Auto` (cualquier otro
 * valor lo rechaza el orquestador en E08 hasta v2).
 */
enum ConfirmationLevel: string
{
    case Auto    = 'auto';
    case Confirm = 'confirm';
    case Manual  = 'manual';
}
