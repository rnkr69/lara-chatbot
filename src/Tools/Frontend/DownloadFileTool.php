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
 * Generates a signed URL with expiration so the widget can download a file
 * from the host's filesystem. Cross-host gap injected in E11 (download of
 * server-side generated documents and attachments).
 *
 * **Exception to the shim pattern**: unlike the rest of the FE primitives,
 * `DownloadFileTool::handle()` DOES execute backend logic — it signs the URL
 * with `Storage::disk()->temporaryUrl()` (S3/R2/cloud) and returns the
 * `download_url` + `expires_at` fields in `ToolResult::data`.
 * `ChatService` (E08, modified in E11) merges them into
 * `frontend_action.args` so the widget does `<a href download>`.
 *
 * Default security model (fail-secure):
 *   - `chatbot.tools.download_file.allowed_disks` lists the enabled disks.
 *     If empty, NO disk is considered signable
 *     (the tool will return `error('runtime', ...)`).
 *   - Direct URLs (`http://`/`https://`) are REFUSED: the tool is not
 *     a proxy for arbitrary URLs; an LLM should not be able to force the
 *     user to download from any domain.
 *   - `assertCanDownload($disk, $path, $ctx)` is a protected hook the
 *     host can override to inject ownership checks
 *     (e.g. "is this PDF the current user's?"). OK by default.
 *   - `expires_in` is clamped to `[30s, max_expires_in]` (default cap 1h).
 *
 * Args (ROADMAP §4/E11):
 *   - `url_or_disk_path` (req) — path on the disk in `disk::path` format
 *     (e.g. `s3::invoices/2026/123.pdf`). Without a prefix the default disk
 *     from `config('filesystems.default')` is assumed.
 *   - `filename` (opt) — suggested name for the browser download.
 *   - `mime` (opt) — expected content-type (informative for the widget).
 *   - `expires_in` (opt) — expiration in seconds. Default 300, max 3600
 *     (configurable in `chatbot.tools.download_file.max_expires_in`).
 *
 * Confirmation: `auto` — the URL is already signed and time-limited;
 * asking for additional confirmation only adds friction.
 *
 * **Subclassing (1.1.4)**: if you extend this tool with your own `name()`
 * (e.g. `DownloadManifestTool` that validates ownership before delegating to
 * `parent::handle()`), also override `frontendPrimitiveName()` to
 * return `'download_file'`. The widget only knows the bundle primitive
 * (`download_file`); without the override the SSE would travel with your
 * custom name and the widget would toast `unknown_tool` (finding #25).
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
            return ToolResult::error('validation', 'url_or_disk_path is required.');
        }

        if (str_starts_with($rawPath, 'http://') || str_starts_with($rawPath, 'https://')) {
            return ToolResult::error('runtime', 'Direct URLs are not allowed; use a disk path (`disk::path`).');
        }

        [$disk, $path] = $this->parseDiskAndPath($rawPath);

        $allowed = $this->allowedDisks();

        if ($allowed === [] || ! in_array($disk, $allowed, true)) {
            return ToolResult::error(
                'runtime',
                "Disk `{$disk}` is not enabled for signed downloads. "
                . 'Configure `chatbot.tools.download_file.allowed_disks` to opt in.'
            );
        }

        if ($path === '') {
            return ToolResult::error('validation', 'Empty path.');
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
                'Could not sign the URL: ' . $e->getMessage(),
            );
        }

        if (! is_string($url) || $url === '') {
            return ToolResult::error('runtime', 'The disk did not return a signed URL.');
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
     * Ownership hook. By default allows any path on an enabled disk — the
     * host must override in a subclass to inject domain checks
     * (e.g. "does this invoice belong to the user?").
     *
     * Return `null` when the download is authorized;
     * `ToolResult::error('not_owner'|'unauthorized', ...)` to deny it.
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
