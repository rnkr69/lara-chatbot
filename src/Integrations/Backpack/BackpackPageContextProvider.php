<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Integrations\Backpack;

use Throwable;

/**
 * Optional page context provider for hosts that use Backpack CRUD
 * (cross-host gap part 1; the full guide goes in E21).
 *
 * Inspects the `CrudPanel` that Backpack exposes via `app('crud')` and
 * derives a `crud: { entity, entity_class?, action, filters,
 * form?, selected_ids }` payload suitable for populating the
 * `<meta name="chatbot:context">` or being passed via `setPageContext()`.
 *
 * v1.1.1 (findings #9.b, #10, #12.b):
 *   - `entity` now emits the friendly name (`"Mission"`) instead of the FQCN
 *     (`"App\\Models\\Mission"`). The FQCN is optionally exposed as
 *     `entity_class` for hosts that need it. Saves tokens.
 *   - When `action ∈ {create, update, edit}`, `form: {id, fields[]}` is added,
 *     derived from `$panel->fields()`, with `options` serialized as
 *     `[{value, label}]` so the LLM maps labels (e.g. "Mars") to HTML
 *     values (e.g. "2") without guessing.
 *   - When `action = list` and `$panel->filters()` is available,
 *     `filters` goes from a flat `{key:value}` to `{applied: {...},
 *     available: [{name, type, options?}]}` so the LLM knows which filters
 *     exist in the grid before proposing changes.
 *
 * v1.1.2 (findings #9.f, #9.g):
 *   - `form.id` is replaced by `form.selector` based on the stable
 *     `bp-section` contract that Backpack 5/6/7 emits in its views
 *     (`<div bp-section="crud-operation-create"><form>...</form></div>`).
 *     The `fill_form` primitive prefers it over `form_id` and resolves without
 *     needing view overrides or an id on the actual `<form>`.
 *   - FK selects (`type:select|select2|...` with `model`/`attribute`/`entity`
 *     declared in Backpack) resolve their `options` server-side, with a
 *     configurable cap (`chatbot.backpack.fk_options_cap`, default 200). If the
 *     enumeration exceeds the cap, `options_truncated: true` is emitted so
 *     the LLM knows it has to fall back to a read tool (e.g. `list_*`) to
 *     resolve labels → ids.
 *
 * Opt-in design (D15): the class does NOT depend on the `backpack/crud`
 * package at the composer level. All introspection is wrapped in try/catch +
 * `class_exists` checks, so that a host that does not use Backpack can
 * resolve this provider without the boot failing. If Backpack is absent, or
 * the panel is not built for the current request,
 * `currentContext()` returns `null`.
 */
class BackpackPageContextProvider
{
    /**
     * Returns a `['crud' => [...]]` array when we are on a Backpack
     * view with a resolved CrudPanel, or `null` in any other case.
     *
     * @return array{crud: array<string, mixed>}|null
     */
    public function currentContext(): ?array
    {
        if (! class_exists('Backpack\\CRUD\\app\\Library\\CrudPanel\\CrudPanel')) {
            return null;
        }

        try {
            $app = function_exists('app') ? app() : null;
            if ($app === null || ! $app->bound('crud')) {
                return null;
            }

            $panel = $app->make('crud');
            if ($panel === null) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $action       = $this->resolveAction($panel);
        $entityName   = $this->resolveEntityName($panel);
        $entityClass  = $this->resolveEntityClass($panel);

        $crud = [
            'entity'       => $entityName,
            'entity_class' => $entityClass,
            'action'       => $action,
            'filters'      => $this->resolveFiltersPayload($panel, $action),
            'form'         => $this->resolveFormSchema($panel, $action, $entityName),
            'selected_ids' => $this->resolveSelectedIds(),
        ];

        // Drop empty / null fields so the meta tag stays compact when Backpack
        // hasn't fully populated the panel yet (e.g. the directive runs
        // very early in the request lifecycle). entity_class is also dropped
        // when it's redundant with entity (i.e. when entity already equals
        // class_basename(entity_class)).
        //
        // v1.1.3 (#17): the comparison is case-insensitive so hosts that set
        // a lowercase entity_name like `"mission"` against `App\Models\Mission`
        // (a common Backpack convention) still see the FQCN dropped from the
        // payload. Saves ~25 tokens/turn on Backpack default deployments.
        if (
            is_string($crud['entity']) && is_string($crud['entity_class'])
            && strcasecmp(class_basename($crud['entity_class']), $crud['entity']) === 0
        ) {
            $crud['entity_class'] = null;
        }

        $crud = array_filter(
            $crud,
            static fn ($v) => $v !== null && $v !== '' && $v !== [],
        );

        if ($crud === []) {
            return null;
        }

        return ['crud' => $crud];
    }

    /**
     * Friendly name of the model (`"Mission"`). Preference:
     * Backpack's `$panel->entity_name` (human-friendly) → class_basename(FQCN).
     */
    protected function resolveEntityName(mixed $panel): ?string
    {
        try {
            if (property_exists($panel, 'entity_name') && is_string($panel->entity_name) && $panel->entity_name !== '') {
                return $panel->entity_name;
            }
        } catch (Throwable) { /* fall through */ }

        $class = $this->resolveEntityClass($panel);

        return $class !== null ? class_basename($class) : null;
    }

    /**
     * FQCN of the panel's model, or the string that Backpack returns (some
     * versions already return it as a string). Returns null if it
     * cannot be resolved. Only exported in `entity_class` when it is not
     * redundant with `entity`.
     */
    protected function resolveEntityClass(mixed $panel): ?string
    {
        try {
            if (method_exists($panel, 'getModel')) {
                $model = $panel->getModel();
                if (is_object($model)) {
                    return $model::class;
                }
                if (is_string($model) && $model !== '') {
                    return $model;
                }
            }
        } catch (Throwable) { /* fall through */ }

        return null;
    }

    protected function resolveAction(mixed $panel): ?string
    {
        try {
            if (method_exists($panel, 'getOperation')) {
                $op = $panel->getOperation();
                if (is_string($op) && $op !== '') {
                    return $op;
                }
            }
            if (method_exists($panel, 'getActionMethod')) {
                $action = $panel->getActionMethod();
                if (is_string($action) && $action !== '') {
                    return $action;
                }
            }
        } catch (Throwable) { /* fall through */ }

        return null;
    }

    /**
     * Panel filters.
     *
     *  - For `list` with `$panel->filters()` available, returns
     *    `['applied' => {...}, 'available' => [{name,type,options?}]]`.
     *  - For other actions (or if filters() does not exist), returns only the
     *    flat array of applied filters (legacy compat).
     *  - Empty list when there are neither applied nor available filters.
     *
     * @return array<string, mixed>
     */
    protected function resolveFiltersPayload(mixed $panel, ?string $action): array
    {
        $applied = $this->resolveAppliedFilters($panel);

        if ($action !== 'list') {
            return $applied;
        }

        try {
            $available = $this->resolveAvailableFilters($panel);
        } catch (Throwable) {
            $available = [];
        }

        if ($available === []) {
            return $applied;
        }

        return [
            'applied'   => (object) $applied, // keep as object even when empty
            'available' => $available,
        ];
    }

    /**
     * @return array<string, scalar>
     */
    protected function resolveAppliedFilters(mixed $panel): array
    {
        try {
            if (method_exists($panel, 'getRequest')) {
                $request = $panel->getRequest();
                if (is_object($request) && method_exists($request, 'query')) {
                    $query = $request->query();
                    if (is_array($query)) {
                        $clean = [];
                        foreach ($query as $key => $value) {
                            if (is_scalar($value)) {
                                $clean[(string) $key] = $value;
                            }
                        }
                        return $clean;
                    }
                }
            }
        } catch (Throwable) { /* fall through */ }

        return [];
    }

    /**
     * List of filters declared in the grid. Each item:
     * `{name, type, options?: [{value,label}]|[string]}`.
     *
     * Backpack exposes filters() as CrudFilter[] (object accessors) or a
     * legacy array — we support both.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveAvailableFilters(mixed $panel): array
    {
        if (! method_exists($panel, 'filters')) {
            return [];
        }

        $raw = $panel->filters();

        if (! is_iterable($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $f) {
            $name    = $this->readFilterField($f, 'name');
            $type    = $this->readFilterField($f, 'type');
            $values  = $this->readFilterField($f, 'values');

            if (! is_string($name) || $name === '') {
                continue;
            }

            $entry = ['name' => $name];
            if (is_string($type) && $type !== '') {
                $entry['type'] = $type;
            }

            $opts = $this->normalizeFilterOptions($values);
            if ($opts !== null) {
                $entry['options'] = $opts;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Reads `$f->name` (object) or `$f['name']` (array) without an invalid
     * access throwing.
     */
    protected function readFilterField(mixed $f, string $key): mixed
    {
        try {
            if (is_object($f)) {
                if (property_exists($f, $key)) {
                    return $f->{$key};
                }
                if (method_exists($f, $key)) {
                    return $f->{$key}();
                }
                if (method_exists($f, 'getAttribute')) {
                    return $f->getAttribute($key);
                }
            }
            if (is_array($f) && array_key_exists($key, $f)) {
                return $f[$key];
            }
        } catch (Throwable) { /* fall through */ }

        return null;
    }

    /**
     * Backpack allows `values` as a callable, an assoc array {value=>label},
     * an array of strings, or null. We always return the normalized form
     * `[{value,label}]` (if it was assoc) or `[string]` (if it was a flat list).
     * Null if there are no useful options.
     *
     * @return array<int, array<string, scalar>|string>|null
     */
    protected function normalizeFilterOptions(mixed $values): ?array
    {
        if (is_callable($values)) {
            try {
                $values = $values();
            } catch (Throwable) {
                return null;
            }
        }

        if (! is_array($values) || $values === []) {
            return null;
        }

        // Detect assoc (keys not 0..n-1).
        $isAssoc = array_keys($values) !== range(0, count($values) - 1);

        if ($isAssoc) {
            $out = [];
            foreach ($values as $v => $l) {
                if (! is_scalar($l)) {
                    continue;
                }
                $out[] = ['value' => $v, 'label' => (string) $l];
            }
            return $out === [] ? null : $out;
        }

        $out = [];
        foreach ($values as $v) {
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * Form schema in `create`/`update`/`edit`. Returns null outside
     * those actions or if introspection fails.
     *
     * Structure:
     *   {
     *     selector: "[bp-section=\"crud-operation-create\"] form",
     *     fields:   [{name, label, type, options?, options_truncated?, required?}, ...]
     *   }
     *
     * `selector` is based on Backpack 5/6/7's stable `bp-section` contract
     * (the views `crud::create.blade.php` / `crud::edit.blade.php` /
     * `crud::inc.form_page.blade.php` wrap the form in
     * `<div bp-section="crud-operation-{op}">`). It works without view
     * overrides and without requiring an `id` on the actual `<form>`.
     *
     * `options` is emitted as `[{value,label}]` for selects with FK / enums
     * so that the LLM maps labels to HTML values without guessing. When
     * the FK enumeration exceeds `chatbot.backpack.fk_options_cap`,
     * `options_truncated: true` signals the LLM that it must fall back to a read
     * tool (list_*) instead of guessing.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveFormSchema(mixed $panel, ?string $action, ?string $entityName): ?array
    {
        if (! in_array($action, ['create', 'update', 'edit'], true)) {
            return null;
        }

        if (! method_exists($panel, 'fields')) {
            return null;
        }

        try {
            $rawFields = $panel->fields();
        } catch (Throwable) {
            return null;
        }

        if (! is_iterable($rawFields)) {
            return null;
        }

        $fields = [];
        foreach ($rawFields as $f) {
            if (! is_array($f)) {
                continue;
            }

            $name = isset($f['name']) && is_scalar($f['name']) ? (string) $f['name'] : '';
            if ($name === '') {
                continue;
            }

            $type = isset($f['type']) && is_string($f['type']) && $f['type'] !== '' ? $f['type'] : 'text';

            // v1.1.3 #22 — Filter fields that aren't user-facing for this
            // operation. This shrinks the prompt and avoids info disclosure
            // when authorization closures hide a field from the current user.
            if ($this->shouldSkipField($f, $type, $action)) {
                continue;
            }

            $entry = [
                'name'  => $name,
                'label' => isset($f['label']) && is_scalar($f['label']) ? (string) $f['label'] : $name,
                'type'  => $type,
            ];

            if (! empty($f['options']) && is_array($f['options'])) {
                $opts = [];
                foreach ($f['options'] as $v => $l) {
                    if (is_scalar($l)) {
                        $opts[] = ['value' => $v, 'label' => (string) $l];
                    }
                }
                if ($opts !== []) {
                    $entry['options'] = $opts;
                }
            }

            // FK select options (#9.g, ampliado en v1.1.3 #18) — resolve via
            // model::query() when Backpack declared the relation. Skipped
            // silently if anything goes wrong; the LLM can still fall back to
            // a read tool.
            if (! isset($entry['options']) && $this->isFkSelectField($f, $panel)) {
                $resolved = $this->resolveFkOptions($f, $panel);
                if ($resolved !== null) {
                    if (isset($resolved['options'])) {
                        $entry['options'] = $resolved['options'];
                    }
                    if (! empty($resolved['truncated'])) {
                        $entry['options_truncated'] = true;
                    }
                }
            }

            if (isset($f['attributes']['required']) && $f['attributes']['required']) {
                $entry['required'] = true;
            }

            $fields[] = $entry;
        }

        if ($fields === []) {
            return null;
        }

        $selectorOp = $action === 'edit' ? 'update' : $action;
        $selector   = '[bp-section="crud-operation-' . $selectorOp . '"] form';

        // `entityName` keeps the field signature stable; not currently used
        // in the payload but kept for parity with v1.1.1 callers.
        unset($entityName);

        return [
            'selector' => $selector,
            'fields'   => $fields,
        ];
    }

    /**
     * Whether a field should be omitted from `crud.form.fields[]` for the
     * current operation (v1.1.3 #22). Skips:
     *
     *   - `type: hidden` → server-side machinery, never user-facing.
     *   - `access` closure that returns false → Backpack-style auth gate.
     *   - `visible_in_create` / `visible_in_update` set to false for the
     *     current op → host-declared visibility flag.
     *
     * Conservative: skip iff we can prove the field is hidden — when in
     * doubt we keep the field so the LLM has more context, not less.
     *
     * @param array<string, mixed> $f
     */
    protected function shouldSkipField(array $f, string $type, ?string $action): bool
    {
        if ($type === 'hidden') {
            return true;
        }

        if (isset($f['access'])) {
            $access = $f['access'];
            if (is_callable($access)) {
                try {
                    if (! call_user_func($access)) {
                        return true;
                    }
                } catch (Throwable) {
                    // If the closure blows up, conservative choice is to
                    // keep the field — degraded UX is better than hidden
                    // info disclosure mishaps from a broken access fn.
                }
            } elseif ($access === false) {
                return true;
            }
        }

        if ($action === 'create' && array_key_exists('visible_in_create', $f) && $f['visible_in_create'] === false) {
            return true;
        }
        if (in_array($action, ['update', 'edit'], true)
            && array_key_exists('visible_in_update', $f) && $f['visible_in_update'] === false) {
            return true;
        }

        return false;
    }

    /**
     * Whether a Backpack field definition refers to a FK relation that the
     * provider can enumerate. We restrict to the well-known select types and
     * require an explicit model (via `model` or `entity` relation, the latter
     * Backpack 6.x's preferred form) so we don't run unbounded queries.
     *
     * Types supported (v1.1.3 #18): the classic `select{,2}{,_multiple}`,
     * plus `select2_from_table`, `select2_from_ajax{,_multiple}`, and the
     * `relationship` field (Backpack 6.x). `select{,2}_from_array` is
     * intentionally excluded — those carry inline options handled upstream.
     *
     * @param array<string, mixed> $f
     */
    protected function isFkSelectField(array $f, mixed $panel = null): bool
    {
        $type = $f['type'] ?? null;
        if (! is_string($type) || $type === '') {
            return false;
        }
        $enumerableTypes = [
            'select', 'select2', 'select_multiple', 'select2_multiple',
            'select2_from_table',
            'select2_from_ajax', 'select2_from_ajax_multiple',
            'relationship',
        ];
        if (! in_array($type, $enumerableTypes, true)) {
            return false;
        }

        // Ajax-backed fields don't need a resolvable model class — we'll
        // signal `truncated=true` without issuing a query, which is more
        // honest than dropping the field.
        $isAjax = in_array($type, ['select2_from_ajax', 'select2_from_ajax_multiple'], true);
        if ($isAjax && ! empty($f['data_source'])) {
            return true;
        }

        return $this->resolveModelClassFromField($f, $panel) !== null;
    }

    /**
     * Resolve the FQCN of the related model from a Backpack field definition.
     * Preference order (v1.1.3 #18):
     *   1. `$f['model']` — explicit FQCN (classic Backpack pattern).
     *   2. `$f['entity']` — relation method name on the panel's model (the
     *      Backpack 6.x idiomatic form). Resolved via reflection-free path:
     *      `$panel->getModel()->{entity}()->getRelated()::class`.
     *
     * Returns null when neither path produces a usable class string.
     *
     * @param array<string, mixed> $f
     * @return class-string|null
     */
    protected function resolveModelClassFromField(array $f, mixed $panel): ?string
    {
        $model = $f['model'] ?? null;
        if (is_string($model) && $model !== '' && class_exists($model)) {
            /** @var class-string $model */
            return $model;
        }

        $entity = $f['entity'] ?? null;
        if (! is_string($entity) || $entity === '' || $panel === null) {
            return null;
        }

        try {
            if (! method_exists($panel, 'getModel')) {
                return null;
            }
            $panelModel = $panel->getModel();
            if (is_string($panelModel) && $panelModel !== '' && class_exists($panelModel)) {
                $instance = new $panelModel();
            } elseif (is_object($panelModel)) {
                $instance = $panelModel;
            } else {
                return null;
            }
            if (! method_exists($instance, $entity)) {
                return null;
            }
            $relation = $instance->{$entity}();
            if (! is_object($relation) || ! method_exists($relation, 'getRelated')) {
                return null;
            }
            $related = $relation->getRelated();
            $class   = is_object($related) ? $related::class : null;

            return is_string($class) && class_exists($class) ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve `[{value,label}]` options for a FK select field. Honors a cap
     * via `chatbot.backpack.fk_options_cap` (default 200); when exceeded the
     * options are dropped and `truncated=true` is signalled instead.
     *
     * Honors a Backpack `options` closure if declared (so host scopes apply).
     *
     * v1.1.3 (#18): supports `entity` (Backpack 6.x relationship) in addition
     * to the legacy `model` FQCN, and short-circuits ajax-backed fields
     * (`select2_from_ajax*` with `data_source`) by signalling `truncated`
     * rather than issuing an HTTP fetch server-side.
     *
     * @param array<string, mixed> $f
     * @return array{options?: list<array{value: mixed, label: string}>, truncated?: bool}|null
     */
    protected function resolveFkOptions(array $f, mixed $panel = null): ?array
    {
        $type = is_string($f['type'] ?? null) ? (string) $f['type'] : '';
        $isAjax = in_array($type, ['select2_from_ajax', 'select2_from_ajax_multiple'], true);

        // Ajax-backed selects: never fetch server-side. Telling the LLM the
        // set is truncated is honest (the dataset is unbounded for us) and
        // it'll fall back to a backend list_* tool to resolve label→id.
        if ($isAjax && ! empty($f['data_source'])) {
            return ['truncated' => true];
        }

        $modelClass = $this->resolveModelClassFromField($f, $panel);
        if ($modelClass === null) {
            return null;
        }

        try {
            $instance   = new $modelClass();
            $attribute  = is_string($f['attribute'] ?? null) && $f['attribute'] !== ''
                ? (string) $f['attribute']
                : (method_exists($instance, 'getKeyName') ? (string) $instance->getKeyName() : 'id');

            $query = $modelClass::query();

            if (isset($f['options']) && is_callable($f['options'])) {
                $scoped = call_user_func($f['options'], $query);
                if ($scoped !== null) {
                    $query = $scoped;
                }
            }

            $cap = $this->resolveFkOptionsCap();

            // Fetch cap+1 so we can detect overflow without two queries.
            $rows = $query->limit($cap + 1)->get();

            if (method_exists($rows, 'count') && $rows->count() > $cap) {
                return ['truncated' => true];
            }

            $opts = [];
            foreach ($rows as $row) {
                $value = method_exists($row, 'getKey') ? $row->getKey() : ($row->{$attribute} ?? null);
                $label = $row->{$attribute} ?? null;
                if ($value === null || ! is_scalar($label)) {
                    continue;
                }
                $opts[] = ['value' => $value, 'label' => (string) $label];
            }

            if ($opts === []) {
                return null;
            }

            return ['options' => $opts];
        } catch (Throwable) {
            return null;
        }
    }

    protected function resolveFkOptionsCap(): int
    {
        try {
            if (function_exists('config')) {
                $cap = config('chatbot.backpack.fk_options_cap', 200);
                if (is_int($cap) && $cap > 0) {
                    return $cap;
                }
                if (is_numeric($cap) && (int) $cap > 0) {
                    return (int) $cap;
                }
            }
        } catch (Throwable) { /* fall through */ }

        return 200;
    }

    /**
     * @return list<int|string>
     */
    protected function resolveSelectedIds(): array
    {
        try {
            if (! function_exists('request')) {
                return [];
            }

            $req = request();
            if ($req === null) {
                return [];
            }

            // Backpack bulk actions ship as `entries[]`; we also accept the
            // generic `selected_ids[]` for hosts that wire their own grids.
            $candidates = ['entries', 'selected_ids'];
            foreach ($candidates as $key) {
                if (! method_exists($req, 'input')) {
                    continue;
                }
                $value = $req->input($key);
                if (! is_array($value)) {
                    continue;
                }
                $clean = [];
                foreach ($value as $item) {
                    if (is_int($item) || (is_string($item) && $item !== '')) {
                        $clean[] = $item;
                    }
                }
                if ($clean !== []) {
                    return $clean;
                }
            }
        } catch (Throwable) { /* fall through */ }

        return [];
    }
}
