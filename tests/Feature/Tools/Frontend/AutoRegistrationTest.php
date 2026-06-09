<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tools\Frontend\DownloadFileTool;
use Rnkr69\LaraChatbot\Tools\Frontend\NavigateTool;
use Rnkr69\LaraChatbot\Tools\ToolRegistry;

/*
|--------------------------------------------------------------------------
| Auto-registro de primitivas FE (E11) — el ServiceProvider registra todas
| las primitivas listadas en `chatbot.tools.frontend_primitives` al boot, y
| el host puede deshabilitar individuales editando esa lista.
|--------------------------------------------------------------------------
*/

it('registers all 8 FE primitives by default at boot', function () {
    $registry = app(ToolRegistry::class);
    $names    = array_keys($registry->all());

    expect($names)->toContain('navigate')
        ->and($names)->toContain('toggle_visibility')
        ->and($names)->toContain('fill_form')
        ->and($names)->toContain('show_toast')
        ->and($names)->toContain('open_modal')
        ->and($names)->toContain('render_block')
        ->and($names)->toContain('invoke_host_action')
        ->and($names)->toContain('download_file')
        ->and($names)->not->toContain('highlight');
});

it('exposes registered primitives as instances of their canonical class', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->get('navigate'))->toBeInstanceOf(NavigateTool::class)
        ->and($registry->get('download_file'))->toBeInstanceOf(DownloadFileTool::class);
});
