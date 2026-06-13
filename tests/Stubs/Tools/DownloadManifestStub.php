<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;

/**
 * Stub for finding #25: a host FE tool that extends a bundle primitive
 * (`DownloadFileTool`) overriding `name()` so the LLM sees it as
 * `download_manifest`, but overriding `frontendPrimitiveName()` so the
 * widget still resolves to the canonical primitive `download_file`.
 *
 * Without the `frontendPrimitiveName()` override, the SSE would travel with
 * `tool=download_manifest` and the widget would toast `unknown_tool`.
 */
class DownloadManifestStub extends DownloadFileTool
{
    public function name(): string
    {
        return 'download_manifest';
    }

    public function frontendPrimitiveName(): string
    {
        return 'download_file';
    }

    public function description(): string
    {
        return 'Downloads the operation manifest; delegates to download_file after validating ownership.';
    }
}
