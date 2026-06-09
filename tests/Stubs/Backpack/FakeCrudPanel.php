<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Tests\Stubs\Backpack;

/**
 * Mínimo CrudPanel-like usado por los tests del
 * `BackpackPageContextProvider`. Simula la superficie pública que el
 * provider lee (`getModel`, `getOperation`, `getActionMethod`,
 * `getRequest`).
 *
 * Tests pueden setear sus campos públicos para variar el escenario.
 */
class FakeCrudPanel
{
    public string|object|null $model = null;
    public ?string $operation = null;
    public ?string $actionMethod = null;
    public mixed $request = null;

    /** Used by the v1.1.1 friendly entity name test. */
    public ?string $entity_name = null;

    /** @var list<array<string, mixed>>|null  Used by the v1.1.1 form schema test. */
    public ?array $fieldsList = null;

    /** @var list<object|array<string, mixed>>|null  Used by the v1.1.1 available filters test. */
    public ?array $filtersList = null;

    public function getModel(): string|object|null
    {
        return $this->model;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function getActionMethod(): ?string
    {
        return $this->actionMethod;
    }

    public function getRequest(): mixed
    {
        return $this->request;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function fields(): ?array
    {
        return $this->fieldsList;
    }

    /**
     * @return list<object|array<string, mixed>>|null
     */
    public function filters(): ?array
    {
        return $this->filtersList;
    }
}
