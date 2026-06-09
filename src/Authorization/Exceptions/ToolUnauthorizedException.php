<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Exceptions;

use RuntimeException;

/**
 * Lanzada cuando un usuario intenta invocar una tool sin permiso, sin
 * scope adecuado o sin ownership sobre el registro objetivo.
 *
 * El mensaje no debe filtrar internals (qué permiso falta, qué columna se
 * filtra, qué tenant). Sólo dice "acceso denegado" y, si se quiere, una
 * categoría general (`unauthorized`, `out_of_scope`, `not_owner`).
 */
class ToolUnauthorizedException extends RuntimeException
{
    public function __construct(
        string $message = 'Acceso denegado.',
        public readonly string $reason = 'unauthorized',
    ) {
        parent::__construct($message);
    }

    public static function forTool(string $toolName, string $reason = 'unauthorized'): self
    {
        return new self(
            message: "Acceso denegado a la tool `{$toolName}`.",
            reason: $reason,
        );
    }
}
