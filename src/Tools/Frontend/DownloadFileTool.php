<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tools\Frontend;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Rnkr69\LaraChatbot\Tools\BaseFrontendTool;
use Rnkr69\LaraChatbot\Tools\ConfirmationLevel;
use Rnkr69\LaraChatbot\Tools\ToolContext;
use Rnkr69\LaraChatbot\Tools\ToolResult;
use Throwable;

/**
 * Genera una URL firmada con expiración para que el widget descargue un
 * archivo del filesystem del host. Gap cross-host inyectado en E11 (descarga
 * de documentos y adjuntos generados server-side).
 *
 * **Excepción al patrón shim**: a diferencia del resto de FE primitivas,
 * `DownloadFileTool::handle()` SÍ ejecuta lógica de backend — firma la URL
 * con `Storage::disk()->temporaryUrl()` (S3/R2/cloud) y devuelve los
 * campos `download_url` + `expires_at` en `ToolResult::data`. El
 * `ChatService` (E08, modificado en E11) los mergea en
 * `frontend_action.args` para que el widget haga `<a href download>`.
 *
 * Modelo de seguridad por defecto (fail-secure):
 *   - `chatbot.tools.download_file.allowed_disks` lista los discos
 *     habilitados. Si está vacía, NINGÚN disk se considera firmable
 *     (la tool devolverá `error('runtime', ...)`).
 *   - URLs directas (`http://`/`https://`) están REHUSADAS: la tool no es
 *     un proxy de URLs arbitrarias; un LLM no debería poder forzar al
 *     usuario a descargar de cualquier dominio.
 *   - `assertCanDownload($disk, $path, $ctx)` es un hook protected que el
 *     host puede override para inyectar comprobaciones de ownership
 *     (ej. "¿es este PDF de OPA del usuario actual?"). Por defecto OK.
 *   - `expires_in` se clampa a `[30s, max_expires_in]` (default cap 1h).
 *
 * Args (ROADMAP §4/E11):
 *   - `url_or_disk_path` (req) — path en el disk en formato `disk::path`
 *     (ej. `s3::invoices/2026/123.pdf`). Sin prefijo se asume el disk
 *     default de `config('filesystems.default')`.
 *   - `filename` (opt) — nombre sugerido para el download del navegador.
 *   - `mime` (opt) — content-type esperado (informativo para el widget).
 *   - `expires_in` (opt) — segundos de expiración. Default 300, max 3600
 *     (configurable en `chatbot.tools.download_file.max_expires_in`).
 *
 * Confirmation: `auto` — la URL ya está firmada y limitada en el tiempo;
 * pedir confirmación adicional sólo añade fricción.
 *
 * **Subclassing (1.1.4)**: si extiendes esta tool con un `name()` propio
 * (ej. `DownloadManifestTool` que valida ownership antes de delegar en
 * `parent::handle()`), override también `frontendPrimitiveName()` para
 * devolver `'download_file'`. El widget sólo conoce el primitive del
 * bundle (`download_file`); sin el override el SSE viajaría con tu nombre
 * custom y el widget toastearía `unknown_tool` (finding #25).
 */
class DownloadFileTool extends BaseFrontendTool
{
    public function name(): string
    {
        return 'download_file';
    }

    public function description(): string
    {
        return 'Generate a time-limited signed URL for the user to download a file from the host (PDFs, invoices, attachments, exports). The widget triggers the download automatically. Provide `url_or_disk_path` formatted as `<disk>::<path>` (e.g. `s3::invoices/2026/123.pdf`); without the disk prefix the configured default disk is used. Optional `filename` (suggested filename for the browser), `mime` (informative content-type), and `expires_in` (seconds, default 300, max 3600). Use this when the user asks to download/save/print/export a file the host has on disk.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url_or_disk_path' => ['type' => 'string', 'description' => 'Path on a host disk, formatted as `<disk>::<path>` or just `<path>` for the default disk. HTTP/HTTPS URLs are rejected.'],
                'filename'         => ['type' => 'string', 'description' => 'Optional filename suggestion for the browser download dialog.'],
                'mime'             => ['type' => 'string', 'description' => 'Optional content-type hint for the widget (e.g. `application/pdf`).'],
                'expires_in'       => ['type' => 'integer', 'description' => 'Signed URL expiration in seconds (default 300, max 3600).'],
            ],
            'required' => ['url_or_disk_path'],
        ];
    }

    public function confirmation(): ConfirmationLevel
    {
        return ConfirmationLevel::Auto;
    }

    public function handle(array $args, ToolContext $ctx): ToolResult
    {
        $rawPath = (string) ($args['url_or_disk_path'] ?? '');

        if ($rawPath === '') {
            return ToolResult::error('validation', 'url_or_disk_path es obligatorio.');
        }

        if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://')) {
            return ToolResult::error('runtime', 'URLs directas no están permitidas; usa un disk path (`disk::path`).');
        }

        [$disk, $path] = $this->parseDiskAndPath($rawPath);

        $allowed = $this->allowedDisks();

        if ($allowed === [] || ! in_array($disk, $allowed, true)) {
            return ToolResult::error(
                'runtime',
                "El disk `{$disk}` no está habilitado para descargas firmadas. "
                . 'Configura `chatbot.tools.download_file.allowed_disks` para opt-in.'
            );
        }

        if ($path === '') {
            return ToolResult::error('validation', 'Path vacío.');
        }

        $ownership = $this->assertCanDownload($disk, $path, $ctx);

        if ($ownership !== null) {
            return $ownership;
        }

        $expiresIn = $this->resolveExpiresIn($args);
        $expiresAt = Carbon::now()->addSeconds($expiresIn);

        try {
            $url = Storage::disk($disk)->temporaryUrl($path, $expiresAt);
        } catch (Throwable $e) {
            return ToolResult::error(
                'runtime',
                'No fue posible firmar la URL: ' . $e->getMessage(),
            );
        }

        if (! is_string($url) || $url === '') {
            return ToolResult::error('runtime', 'El disk no devolvió una URL firmada.');
        }

        $payload = [
            'download_url' => $url,
            'expires_at'   => $this->formatExpiry($expiresAt),
        ];

        if (isset($args['filename']) && is_string($args['filename']) && $args['filename'] !== '') {
            $payload['filename'] = $args['filename'];
        }

        if (isset($args['mime']) && is_string($args['mime']) && $args['mime'] !== '') {
            $payload['mime'] = $args['mime'];
        }

        return ToolResult::success($payload);
    }

    /**
     * Hook de ownership. Por defecto permite cualquier path en un disk
     * habilitado — el host debe override en una subclase para inyectar
     * comprobaciones de dominio (ej. "¿este invoice pertenece al usuario?").
     *
     * Devolver `null` cuando el download está autorizado;
     * `ToolResult::error('not_owner'|'unauthorized', ...)` para denegarlo.
     */
    protected function assertCanDownload(string $disk, string $path, ToolContext $ctx): ?ToolResult
    {
        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseDiskAndPath(string $raw): array
    {
        if (str_contains($raw, '::')) {
            [$disk, $path] = explode('::', $raw, 2);

            return [trim($disk), ltrim($path, '/')];
        }

        $defaultDisk = config('filesystems.default', 'local');

        return [
            is_string($defaultDisk) && $defaultDisk !== '' ? $defaultDisk : 'local',
            ltrim($raw, '/'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function allowedDisks(): array
    {
        $configured = config('chatbot.tools.download_file.allowed_disks', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? $v : null, $configured),
            static fn ($v) => $v !== null && $v !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    protected function resolveExpiresIn(array $args): int
    {
        $requested = isset($args['expires_in']) && is_numeric($args['expires_in'])
            ? (int) $args['expires_in']
            : 300;

        $max = (int) config('chatbot.tools.download_file.max_expires_in', 3600);
        if ($max < 30) {
            $max = 3600;
        }

        return max(30, min($requested, $max));
    }

    protected function formatExpiry(CarbonInterface $expiresAt): string
    {
        return $expiresAt->toIso8601String();
    }
}
