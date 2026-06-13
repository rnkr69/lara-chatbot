<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Llm\Exceptions;

use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Exceptions\PrismServerException;
use Prism\Prism\Exceptions\PrismStreamDecodeException;
use RuntimeException;
use Throwable;

/**
 * Exception that wraps any LLM failure (Prism or otherwise) so that the
 * rest of the package (ChatService, commands, controllers) does not have to
 * know the SDK's internal hierarchy.
 *
 * Categorized by `reason`:
 *   - `rate_limit`        — provider requested rate-limit; retry later.
 *   - `overloaded`        — provider overloaded.
 *   - `auth`              — invalid or expired credentials.
 *   - `request_too_large` — context exceeds the model's limit.
 *   - `server`            — provider 5xx error.
 *   - `stream_decode`     — failure decoding the provider's stream.
 *   - `unknown`           — any other failure.
 */
class LlmException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'unknown',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Converts a Prism exception (or any `Throwable`) into an
     * `LlmException` with a classified `reason`. The "auth" detection
     * inspects the message because Prism does not expose a specific class
     * for invalid credentials (it varies by provider).
     */
    public static function fromPrism(Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        $reason = match (true) {
            $e instanceof PrismRateLimitedException     => 'rate_limit',
            $e instanceof PrismProviderOverloadedException => 'overloaded',
            $e instanceof PrismRequestTooLargeException => 'request_too_large',
            $e instanceof PrismStreamDecodeException    => 'stream_decode',
            $e instanceof PrismServerException          => 'server',
            self::looksLikeAuthError($e)                => 'auth',
            $e instanceof PrismException                => 'unknown',
            default                                     => 'unknown',
        };

        return new self($e->getMessage(), $reason, $e);
    }

    protected static function looksLikeAuthError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unauthorized')
            || str_contains($message, 'invalid api key')
            || str_contains($message, 'authentication')
            || str_contains($message, '401');
    }
}
