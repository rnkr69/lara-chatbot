<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $files = new Filesystem;
    $target = app_path('Chatbot');

    if ($files->isDirectory($target)) {
        $files->deleteDirectory($target);
    }
});

it('generates a tenant resolver stub in app/Chatbot/', function () {
    $exit = $this->artisan('chatbot:make:tenant-resolver', ['name' => 'CorporationTenantResolver'])->run();

    $path = app_path('Chatbot/CorporationTenantResolver.php');

    expect($exit)->toBe(0)
        ->and(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('class CorporationTenantResolver implements TenantResolver')
        ->and($contents)->toContain('Rnkr69\\LaraChatbot\\Authorization\\Contracts\\TenantResolver')
        ->and($contents)->toContain('resolveAccessibleTenantIds');
});
