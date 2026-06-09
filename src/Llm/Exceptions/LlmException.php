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
 * Excepción que envuelve cualquier fallo del LLM (Prism u otro) para que el
 * resto del paquete (ChatService, comandos, controladores) no tenga que
 * conocer la jerarquía interna del SDK.
 *
 * Se categoriza por `reason`:
 *   - `rate_limit`        — proveedor pidió rate-limit; reintentar más tarde.
 *   - `overloaded`        — proveedor sobrecargado.
 *   - `auth`              — credenciales inválidas o caducadas.
 *   - `request_too_large` — contexto excede el límite del modelo.
 *   - `server`            — error 5xx del proveedor.
 *   - `stream_decode`     — fallo decodificando el stream del proveedor.
 *   - `unknown`           — cualquier otro fallo.
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
     * Convierte una excepción de Prism (o cualquier `Throwable`) en una
     * `LlmException` con `reason` clasificado. La detección de "auth"
     * inspecciona el mensaje porque Prism no expone una clase específica
     * para credenciales inválidas (varía por proveedor).
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
