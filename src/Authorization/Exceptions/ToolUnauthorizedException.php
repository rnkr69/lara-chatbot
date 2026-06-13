<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Authorization\Exceptions;

use RuntimeException;

/**
 * Thrown when a user tries to invoke a tool without permission, without an
 * adequate scope, or without ownership over the target record.
 *
 * The message must not leak internals (which permission is missing, which
 * column is filtered, which tenant). It only says "access denied" and,
 * optionally, a general category (`unauthorized`, `out_of_scope`,
 * `not_owner`).
 */
class ToolUnauthorizedException extends RuntimeException
{
    public function __construct(
        string $message = 'Access denied.',
        public readonly string $reason = 'unauthorized',
    ) {
        parent::__construct($message);
    }

    public static function forTool(string $toolName, string $reason = 'unauthorized'): self
    {
        return new self(
            message: "Access denied to tool `{$toolName}`.",
            reason: $reason,
        );
    }
}
