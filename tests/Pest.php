<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| TestCase asignado a Unit y Feature: la suite Unit usa por defecto el
| TestCase nativo de PHPUnit (más rápido, sin Laravel); Feature usa el
| TestCase de Orchestra Testbench que arranca la app + ChatbotServiceProvider.
|
| Si un test concreto en Unit necesita la app de Laravel, declarar
| `uses(\Rnkr69\LaraChatbot\Tests\TestCase::class);` en el archivo del test.
|
*/

uses(\Rnkr69\LaraChatbot\Tests\TestCase::class)->in('Feature');
uses(\Rnkr69\LaraChatbot\Tests\TestCase::class)->in('Unit');
uses(\Rnkr69\LaraChatbot\Tests\TestCase::class)->in('Evals');
