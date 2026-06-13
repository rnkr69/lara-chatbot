<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $files = new Filesystem;
    $target = app_path('Chatbot/Tools');

    if ($files->isDirectory($target)) {
        $files->deleteDirectory($target);
    }
});

it('generates a read tool stub with the snake_case name baked in', function () {
    $exit = $this->artisan('chatbot:make:tool', ['name' => 'ListMyInvoices'])->run();

    $path = app_path('Chatbot/Tools/ListMyInvoices.php');

    expect($exit)->toBe(0)
        ->and(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('class ListMyInvoices extends BaseBackendTool')
        ->and($contents)->toContain("return 'list_my_invoices';")
        ->and($contents)->toContain('Rnkr69\\LaraChatbot\\Tools\\BaseBackendTool');
});

it('generates a write tool stub when --type=write is given', function () {
    $exit = $this->artisan('chatbot:make:tool', ['name' => 'ApproveOrder', '--type' => 'write'])->run();

    $path = app_path('Chatbot/Tools/ApproveOrder.php');

    expect($exit)->toBe(0)
        ->and(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('class ApproveOrder extends BaseBackendTool')
        ->and($contents)->toContain("return 'approve_order';")
        // Write-stub differentiator: a comment about idempotency.
        ->and($contents)->toContain('Idempotency');
});
