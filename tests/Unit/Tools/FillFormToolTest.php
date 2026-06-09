<?php

declare(strict_types=1);

use Rnkr69\LaraChatbot\Tools\Frontend\FillFormTool;

it('parameters() no longer marks form_id as required (v1.1.1, finding #9.a)', function () {
    $tool = new FillFormTool;

    $parameters = $tool->parameters();

    expect($parameters['required'] ?? [])->toBe(['fields']);
    expect($parameters['properties']['form_id'] ?? null)->toBeArray();
});

it('description() advertises auto-discovery + data-chatbot-field alias', function () {
    $tool = new FillFormTool;

    $description = $tool->description();

    expect($description)->toContain('auto-discovers');
    expect($description)->toContain('data-chatbot-form');
});
