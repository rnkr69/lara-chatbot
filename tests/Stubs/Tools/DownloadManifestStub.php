<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Tools;

use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;

/**
 * Stub para finding #25: una FE tool del host que extiende un primitive
 * del bundle (`DownloadFileTool`) sobrescribiendo `name()` para que el
 * LLM la vea como `download_manifest`, pero override
 * `frontendPrimitiveName()` para que el widget siga resolviendo al
 * primitive canónico `download_file`.
 *
 * Sin el override de `frontendPrimitiveName()`, el SSE viajaría con
 * `tool=download_manifest` y el widget toastearía `unknown_tool`.
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
        return 'Descarga el manifest de la operación; delega en download_file tras validar ownership.';
    }
}
