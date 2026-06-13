<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Rnkr69\LaraChatbot\Integrations\Backpack\BackpackPageContextProvider;
use Rnkr69\LaraChatbot\Tests\Stubs\Backpack\FakeCrudPanel;

/*
 * These tests simulate Backpack's presence at runtime via a `class_alias`
 * that points the real CrudPanel FQCN at the test package's `FakeCrudPanel`.
 * The provider does not distinguish (it reads the API by name) and can be
 * exercised on its "happy path" without a real Backpack.
 *
 * The alias is declared only once; PHP keeps the global binding for the
 * entire suite. This does NOT affect other tests because the provider only
 * does a one-off `class_exists()` check and never touches concrete classes
 * at runtime — and besides, nothing outside these files references that FQCN.
 */
if (! class_exists('Backpack\\CRUD\\app\\Library\\CrudPanel\\CrudPanel', false)) {
    class_alias(FakeCrudPanel::class, 'Backpack\\CRUD\\app\\Library\\CrudPanel\\CrudPanel');
}

beforeEach(function () {
    $this->panel = new FakeCrudPanel;
    app()->instance('crud', $this->panel);
});

it('reads entity, action and filters from the resolved CrudPanel', function () {
    $this->panel->model = 'App\\Models\\Invoice';
    $this->panel->operation = 'list';
    $this->panel->request = Request::create(
        '/admin/invoices?status=open&search=acme',
        'GET',
    );

    $context = (new BackpackPageContextProvider)->currentContext();

    // v1.1.1: entity is the friendly basename ("Invoice"). When
    // class_basename($entity_class) equals the friendly entity name, the
    // provider drops entity_class to avoid token waste.
    expect($context)->toMatchArray([
        'crud' => [
            'entity'  => 'Invoice',
            'action'  => 'list',
            'filters' => ['status' => 'open', 'search' => 'acme'],
        ],
    ]);
    expect($context['crud'])->not->toHaveKey('entity_class');
});

it('falls back to getActionMethod when getOperation is empty', function () {
    $this->panel->model = 'App\\Models\\Order';
    $this->panel->operation = null;
    $this->panel->actionMethod = 'edit';

    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context['crud']['action'] ?? null)->toBe('edit');
});

it('extracts selected_ids from the entries[] request param (Backpack bulk)', function () {
    $this->panel->model = 'App\\Models\\Invoice';
    $this->panel->operation = 'list';

    // Backpack convention: bulk actions ship the selection as `entries[]`.
    $request = Request::create('/admin/invoices', 'POST', ['entries' => [11, 22, 33]]);
    app()->instance('request', $request);

    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context['crud']['selected_ids'] ?? null)->toBe([11, 22, 33]);
});

it('returns null when the panel has no useful fields populated', function () {
    // Bare panel: no model, no operation, no method.
    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context)->toBeNull();
});

it('returns null when the crud binding is missing', function () {
    app()->forgetInstance('crud');
    // forgetInstance only clears resolved singletons; we also need to drop
    // the binding declaration so `bound()` returns false.
    /** @var \Illuminate\Foundation\Application $app */
    $app = app();
    $reflection = new ReflectionClass($app);
    foreach (['bindings', 'instances', 'aliases'] as $prop) {
        if ($reflection->hasProperty($prop)) {
            $rp = $reflection->getProperty($prop);
            $rp->setAccessible(true);
            $values = $rp->getValue($app);
            unset($values['crud']);
            $rp->setValue($app, $values);
        }
    }

    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context)->toBeNull();
});

it('drops non-scalar request filters', function () {
    $this->panel->model = 'App\\Models\\Invoice';
    $this->panel->operation = 'list';
    $this->panel->request = Request::create(
        '/admin/invoices?nested[a]=1&simple=ok',
        'GET',
    );

    $context = (new BackpackPageContextProvider)->currentContext();

    // `nested` is an array → dropped; `simple` survives.
    expect($context['crud']['filters'] ?? null)->toBe(['simple' => 'ok']);
});

// ===== v1.1.1 (findings #9.b, #10, #12.b) =====

it('emits the friendly entity name when entity_name is set', function () {
    $this->panel->model = 'App\\Models\\InterstellarMission';
    $this->panel->entity_name = 'mission';
    $this->panel->operation = 'list';

    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context['crud']['entity'] ?? null)->toBe('mission');
    // entity_class is kept because friendly name ("mission") doesn't equal
    // class_basename ("InterstellarMission") even after case-folding.
    expect($context['crud']['entity_class'] ?? null)->toBe('App\\Models\\InterstellarMission');
});

it('drops entity_class when friendly entity differs only in case (v1.1.3, finding #17)', function () {
    // Common Backpack convention: hosts declare `entity_name = "mission"`
    // (lowercase, singular) against `App\Models\Mission`. The v1.1.2 guard
    // compared exact strings and emitted both fields — now they collapse
    // case-insensitively, saving ~25 tokens/turn on the typical setup.
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->entity_name = 'mission';
    $this->panel->operation = 'list';

    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context['crud']['entity'] ?? null)->toBe('mission');
    expect($context['crud'])->not->toHaveKey('entity_class');
});

it('emits crud.form schema for create/update with options serialized as value/label', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'origin_planet_id', 'label' => 'Origin planet', 'type' => 'select',
         'options' => [1 => 'Earth', 2 => 'Mars']],
        ['name' => 'departure_at', 'label' => 'Departure at', 'type' => 'datetime'],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'enum',
         'options' => ['standard' => 'Standard', 'express' => 'Express']],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    // v1.1.2 (#9.f): provider emits `selector` based on the `bp-section`
    // contract of Backpack 5/6/7 instead of a fabricated `id` that never
    // materialized in the DOM. `id` is no longer in the payload.
    expect($context['crud']['form'] ?? null)->not->toHaveKey('id');
    expect($context['crud']['form']['selector'] ?? null)
        ->toBe('[bp-section="crud-operation-create"] form');
    $fields = $context['crud']['form']['fields'] ?? [];
    expect($fields)->toHaveCount(3);
    expect($fields[0]['name'])->toBe('origin_planet_id');
    expect($fields[0]['options'])->toBe([
        ['value' => 1, 'label' => 'Earth'],
        ['value' => 2, 'label' => 'Mars'],
    ]);
    expect($fields[1]['type'])->toBe('datetime');
});

it('maps action=edit to operation=update in the form selector', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'edit';
    $this->panel->fieldsList = [
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'text'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    // Backpack's built-in `edit` operation renders inside
    // `bp-section="crud-operation-update"` (the wrapper key is `update`,
    // not `edit`).
    expect($context['crud']['form']['selector'] ?? null)
        ->toBe('[bp-section="crud-operation-update"] form');
});

it('does NOT emit crud.form when action is list', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'list';
    $this->panel->fieldsList = [
        ['name' => 'origin_planet_id', 'type' => 'select', 'options' => [1 => 'Earth']],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    expect($context['crud'])->not->toHaveKey('form');
});

it('emits filters as {applied, available} when action is list and filters() are declared', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'list';
    $this->panel->request = Request::create('/admin/mission?status=approved', 'GET');
    $this->panel->filtersList = [
        (object) ['name' => 'status', 'type' => 'dropdown', 'values' => ['draft' => 'Draft', 'approved' => 'Approved']],
        (object) ['name' => 'departure_at', 'type' => 'date_range', 'values' => null],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $filters = $context['crud']['filters'] ?? null;
    expect($filters)->toBeArray();
    expect($filters)->toHaveKey('applied');
    expect($filters)->toHaveKey('available');
    expect($filters['available'])->toHaveCount(2);
    expect($filters['available'][0]['name'])->toBe('status');
    expect($filters['available'][0]['options'])->toBe([
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'approved', 'label' => 'Approved'],
    ]);
});

// ===== v1.1.3 (finding #18) — Expanded FK select types =====

it('marks select2_from_ajax fields as options_truncated without issuing a DB query (v1.1.3, finding #18)', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'ship_id', 'label' => 'Ship', 'type' => 'select2_from_ajax',
         'model' => 'App\\Models\\Ship', 'data_source' => '/admin/ship/ajax'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $fields = $context['crud']['form']['fields'] ?? [];
    expect($fields)->toHaveCount(1);
    expect($fields[0]['name'])->toBe('ship_id');
    expect($fields[0]['type'])->toBe('select2_from_ajax');
    expect($fields[0]['options_truncated'] ?? null)->toBeTrue();
    expect($fields[0])->not->toHaveKey('options');
});

it('treats select2_from_table as an enumerable FK type (v1.1.3, finding #18)', function () {
    // The model class doesn't exist at runtime, so enumeration silently
    // returns null and no `options` are emitted — but the type now passes
    // the isFkSelectField gate, which is the regression we want to lock.
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'pilot_id', 'label' => 'Pilot', 'type' => 'select2_from_table',
         'model' => 'App\\Models\\NonExistentPilot', 'entity' => 'pilot'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $fields = $context['crud']['form']['fields'] ?? [];
    expect($fields)->toHaveCount(1);
    expect($fields[0]['name'])->toBe('pilot_id');
    expect($fields[0]['type'])->toBe('select2_from_table');
});

// ===== v1.1.3 (finding #22) — Filter hidden / access / visible_in_* =====

it('omits fields with type=hidden from crud.form.fields (v1.1.3, finding #22)', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'fleet_id', 'label' => 'Fleet', 'type' => 'hidden'],
        ['name' => 'name', 'label' => 'Name', 'type' => 'text'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $fields = $context['crud']['form']['fields'] ?? [];
    expect($fields)->toHaveCount(1);
    expect($fields[0]['name'])->toBe('name');
});

it('omits fields whose access closure returns false (v1.1.3, finding #22)', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'internal_remarks', 'label' => 'Internal remarks', 'type' => 'textarea',
         'access' => fn () => false],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea',
         'access' => fn () => true],
        ['name' => 'name', 'label' => 'Name', 'type' => 'text'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $fields = $context['crud']['form']['fields'] ?? [];
    $names = array_column($fields, 'name');
    expect($names)->toBe(['remarks', 'name']);
});

it('omits fields with visible_in_create=false for create op (v1.1.3, finding #22)', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'computed_at', 'type' => 'datetime', 'visible_in_create' => false],
        ['name' => 'name', 'type' => 'text'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $fields = $context['crud']['form']['fields'] ?? [];
    expect(array_column($fields, 'name'))->toBe(['name']);
});

it('omits fields with visible_in_update=false for edit op (v1.1.3, finding #22)', function () {
    $this->panel->model = 'App\\Models\\Mission';
    $this->panel->operation = 'edit';
    $this->panel->fieldsList = [
        ['name' => 'immutable_code', 'type' => 'text', 'visible_in_update' => false],
        ['name' => 'name', 'type' => 'text'],
    ];

    $context = (new BackpackPageContextProvider)->currentContext();

    $fields = $context['crud']['form']['fields'] ?? [];
    expect(array_column($fields, 'name'))->toBe(['name']);
});

it('resolves model class from the `entity` relation when `model` is absent (v1.1.3, finding #18)', function () {
    // Backpack 6.x: hosts declare `entity` (the relation name on the parent
    // model) instead of a `model` FQCN. The provider should follow the
    // relation to recover the related model class.
    $panelModel = new class {
        public function pilot(): object
        {
            return new class {
                public function getRelated(): object
                {
                    return new \Rnkr69\LaraChatbot\Tests\Stubs\TestUser();
                }
            };
        }
    };

    $this->panel->model = $panelModel;
    $this->panel->operation = 'create';
    $this->panel->fieldsList = [
        ['name' => 'pilot_id', 'label' => 'Pilot', 'type' => 'relationship',
         'entity' => 'pilot'],
    ];

    $provider = new class extends BackpackPageContextProvider {
        public function exposeResolve(array $f, mixed $panel): ?string
        {
            return $this->resolveModelClassFromField($f, $panel);
        }
        public function exposeIsFk(array $f, mixed $panel): bool
        {
            return $this->isFkSelectField($f, $panel);
        }
    };

    // The provider receives the CrudPanel; the relation lookup happens via
    // $panel->getModel()->{entity}()->getRelated()::class.
    $resolved = $provider->exposeResolve(
        ['type' => 'relationship', 'entity' => 'pilot'],
        $this->panel,
    );
    expect($resolved)->toBe(\Rnkr69\LaraChatbot\Tests\Stubs\TestUser::class);

    expect($provider->exposeIsFk(
        ['type' => 'relationship', 'entity' => 'pilot'],
        $this->panel,
    ))->toBeTrue();
});
