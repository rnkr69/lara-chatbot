<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Exceptions;

use LogicException;

/**
 * Lanzada por `ToolRegistry::register()` cuando una tool declara
 * `tenantScope=true` pero el host no ha bind un `TenantResolver` (gap
 * cross-host E04). Es el "boot ruidoso" mencionado en §4 E04 de
 * PROGRESS.md.
 *
 * Mensaje accionable: el integrador sabe qué tool dispara el fallo y qué
 * config falta.
 */
class MissingTenantResolverException extends LogicException
{
    public static function forTool(string $toolName): self
    {
        return new self(
            "La tool `{$toolName}` declara `tenantScope=true` pero no hay "
            . 'TenantResolver registrado en el contenedor. Bind una clase '
            . 'que implemente Rnkr69\\LaraChatbot\\Authorization\\Contracts\\TenantResolver '
            . 'vía `chatbot.authorization.tenant_resolver` o desde tu '
            . 'AppServiceProvider antes de boot().'
        );
    }
}
