<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Exceptions;

use LogicException;

/**
 * Thrown by `ToolRegistry::register()` when a tool declares
 * `tenantScope=true` but the host has not bound a `TenantResolver` (E04
 * cross-host gap). It is the "noisy boot" mentioned in §4 E04 of
 * PROGRESS.md.
 *
 * Actionable message: the integrator knows which tool triggers the failure
 * and what config is missing.
 */
class MissingTenantResolverException extends LogicException
{
    public static function forTool(string $toolName): self
    {
        return new self(
            "Tool `{$toolName}` declares `tenantScope=true` but no "
            . 'TenantResolver is registered in the container. Bind a class '
            . 'that implements Rnkr69\\LaraChatbot\\Authorization\\Contracts\\TenantResolver '
            . 'via `chatbot.authorization.tenant_resolver` or from your '
            . 'AppServiceProvider before boot().'
        );
    }
}
