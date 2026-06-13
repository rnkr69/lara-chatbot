# Integration · Custom forms (no-Backpack)

*English · [Español](custom-forms.es.md)*

> How to integrate `fill_form` with `<form>` elements that do not come from Backpack
> (plain Blade, Livewire, Inertia, Filament — any stack).
>
> Pre-reading: [`page-context.md`](../page-context.md) +
> [`FRONTEND_TOOLS.md`](../FRONTEND_TOOLS.md). For hosts that DO use
> Backpack the integration is out-of-the-box — see
> [`integrations/backpack.md`](backpack.md).

---

## 1. Why this doc?

`BackpackPageContextProvider` introspects the `CrudPanel` to emit the
form schema (fields, types, options). Any "standard" Laravel app lacks
that mechanism: the `<form>` lives in a plain Blade view / Livewire
component / Inertia page, and without help from the integrator the LLM
does not know what HTML `name` each control has or what values the
selects expect.

The package offers three pieces to close that gap:

| Piece | What it does |
|---|---|
| `@chatbotForm` Blade directive | tags the `<form>` and publishes its schema to the page context |
| `chatbot:scan-forms` | audit: lists tagged vs. untagged forms |
| `chatbot:integrate-form` | interactive wizard that adds `@chatbotForm` to a specific form |

With these, the LLM sees the form schema under `## Current page` and can
call `fill_form` with the correct `name` and `value` without guessing.

---

## 2. The `@chatbotForm` directive

### 2.1 Syntax

```blade
<form method="post"
      action="{{ route('contact.store') }}"
      @chatbotForm('contact-form', $schemaSource)>
    @csrf
    <input name="name" type="text" required>
    <input name="email" type="email" required>
    ...
</form>
```

The first argument is the **id** the LLM will use in `form_id`. The
second (optional) is the schema **source**. Three variants are
supported:

### 2.2 Variant 1 — FormRequest FQCN (recommended)

```blade
<form method="post" action="{{ route('contact.store') }}"
      @chatbotForm('contact-form', App\Http\Requests\ContactRequest::class)>
    ...
</form>
```

The directive instantiates the FormRequest, reads `rules()`, and maps
each rule to a schema field via `Rnkr69\LaraChatbot\Validation\RulesToFormSchema`.
Supported mappings:

| Laravel rule | Generated schema |
|---|---|
| `'required'` | `required: true` |
| `'email'` | `type: 'email'` |
| `'integer'` / `'numeric'` | `type: 'number'` |
| `'boolean'` | `type: 'boolean'` |
| `'date'` | `type: 'date'` |
| `'date_format:Y-m-d H:i'` | `type: 'datetime'` |
| `'url'` | `type: 'url'` |
| `'file'` / `'image'` | `type: 'file'` |
| `'in:a,b,c'` or `Rule::in([...])` | `type: 'select'`, `options: ['a','b','c']` |
| `'max:255'` / `'min:1'` | `max: 255` / `min: 1` |

If the FormRequest implements `attributes()` (human-friendly labels), the
directive uses them as `label`:

```php
class ContactRequest extends FormRequest
{
    public function rules(): array { return [/* ... */]; }
    public function attributes(): array
    {
        return ['email' => 'Tu correo electrónico'];
    }
}
```

→ the schema emits `{name: 'email', label: 'Tu correo electrónico', type: 'email', required: true}`.

**Advantage**: rules are the single source of truth — when you change
the validation, the LLM schema updates automatically. **Edge case**:
array-nested rules (`items.*.name`) are ignored (the LLM cannot fill
array fields cleanly at this time). Hosts with heavily array-based forms
should fall back to variant 2 or extend the mapper.

### 2.3 Variant 2 — Inline list

```blade
<form method="post" action="{{ route('contact.store') }}"
      @chatbotForm('contact-form', [
          ['name' => 'name',    'type' => 'text',     'required' => true],
          ['name' => 'email',   'type' => 'email',    'required' => true],
          ['name' => 'subject', 'type' => 'select',
           'options' => [
               ['value' => 'support', 'label' => 'Support'],
               ['value' => 'sales',   'label' => 'Sales'],
           ]],
          ['name' => 'message', 'type' => 'textarea', 'required' => true],
      ])>
    ...
</form>
```

The **simplest and most predictable** form. Useful when:

- There is no FormRequest (the form goes directly to the controller).
- You want to expose custom options that do not come from rules (e.g.
  friendly labels for enums).
- Your form has fields that do NOT map 1:1 to rules (a hidden field, a
  computed field).

### 2.4 Variant 3 — Runtime-extracted (no schema)

```blade
<form method="post" action="..." @chatbotForm('contact-form')>
    @csrf
    <input name="name" required>
    <input name="email" type="email" required>
    ...
</form>
```

No second argument. The directive emits the `data-chatbot-form`
attribute plus a lightweight `<script>` that, on widget boot, scans the
form and publishes `{name, type, required}` extracted from the DOM via
`Chatbot.setPageContext({form: {...}})`.

The schema is less rich (no friendly labels, no select options), but
useful as a starting point when you do not want to touch any existing
code. Migrate to variant 1 or 2 when UX demands it.

---

## 3. Aliases with `data-chatbot-field`

For fields with an ugly HTML `name` (`metadata[options][0][value]`,
`payload[items][3][qty]`), expose a friendly alias for the LLM to use:

```html
<input name="metadata[options][0][value]" data-chatbot-field="first_option">
```

The LLM sees `first_option` in the page context schema (when declared in
variant 1/2) and `fillForm` searches by `[data-chatbot-field]` before
`[name]`. If the LLM calls with a name that does not exist, the
"field not found" warning lists BOTH sets (`name` and `data-chatbot-field`)
for diagnostics.

---

## 4. Combining with a write tool

Sometimes you want the LLM to also be able to **submit** the form when
the user is not on `/contact`. Pattern:

1. Keep `@chatbotForm('contact-form', ContactRequest::class)` in the
   view (so that when the user is on the page the LLM can pre-fill
   the form).
2. Create a backend write tool `submit_contact_form` that validates
   with `ContactRequest::rules()` (see
   [`backend-tools.md`](../backend-tools.md)) and persists.
3. The LLM decides route by route: if the user is on `/contact` →
   `fill_form` (better UX, user reviews); if on another page →
   `submit_contact_form` directly.

Both sites consume the SAME rules class — no drift between front (what
the LLM sees) and back (what the server validates).

---

## 5. Support commands

### 5.1 `chatbot:scan-forms` — audit

```
php artisan chatbot:scan-forms
```

Scans `resources/views/**/*.blade.php` and reports each `<form>` with
its status:

```
 +----------------------------------------+-------------------+----------------+----------+
 | View                                   | Route hint        | data-chatbot-form | Status |
 +----------------------------------------+-------------------+----------------+----------+
 | contact.blade.php                      | contact.store     | (none)         | Untagged |
 | profile/edit.blade.php                 | profile.update    | profile-form   | Tagged   |
 | checkout.blade.php                     | checkout.submit   | (none)         | Untagged |
 +----------------------------------------+-------------------+----------------+----------+

 Summary: 1 tagged, 2 untagged.
```

Flags:
- `--path=resources/views/admin` to scan only a subdirectory.
- `--json` for programmatic output.

It is **read-only** — it modifies nothing.

### 5.2 `chatbot:integrate-form <view>` — wizard

```
php artisan chatbot:integrate-form resources/views/contact.blade.php
```

Locates the first `<form>` in the Blade file and asks:
- What id to use (default: slug of the basename).
- What schema source (FormRequest / manual list / runtime-extracted).
- Shows the diff before applying.

It is **idempotent**: if the form already has `@chatbotForm` or
`data-chatbot-form`, it skips with a message (use `--force` to
overwrite).

Useful flags:
- `--request=App\Http\Requests\ContactRequest` for non-interactive use.
- `--id=contact-main` to force a specific id.

---

## 6. Edge cases

### 6.1 Forms with `_method=PUT/DELETE`

The LLM is unaware of the HTTP verb because it only sees the field
schema. That is fine — `fill_form` does not submit by default
(`submit: false`). If it does submit (`submit: true`), the `<form>`
already emits the correct `_method` because Laravel injects the hidden
field via `@method`. No additional action needed.

### 6.2 Forms with file uploads (`multipart/form-data`)

`fill_form` fills `<input type="file">` with `''` (empty) if the LLM
passes a value — there is no way to upload a file from the conversation
in v1.1.x. If you want the LLM to "send" a file, consider a dedicated
write tool that receives a URL and performs the upload server-side.

### 6.3 Multi-step forms (wizard)

Each step should be an independent `<form>` with its own `@chatbotForm`.
The LLM sees the current step's form via page context and can fill it.
When advancing a step, the host emits `chatbot:context-changed`
(manually via `setPageContext` or via re-render) to refresh the schema.

### 6.4 Livewire 3.x

`wire:model` listens to `input` events. Since `fillForm` already fires
`input` and `change` as of v1.0.1, Livewire reacts normally. No extra
configuration is needed. Verify that the `<form>` (or
`<div wire:submit="save">`) has `data-chatbot-form` so that `fill_form`
can locate it — Livewire does NOT emit forms with an `id` by default.

### 6.5 Inertia

Inertia forms are JSX/Vue/Svelte rendered client-side. The pattern is:
1. In the Blade root layout (`app.blade.php`), expose the schema via
   `<meta name="chatbot:context-form" content='...'>` or via
   `Chatbot.setPageContext({form: ...})` in the client component.
2. Ensure the final rendered `<form>` has `data-chatbot-form="..."`.
   For Vue/React, add it as a prop or direct attribute on the `<form>`.

The `@chatbotForm` directive cannot reach a `<form>` rendered by the
client-side framework — you would need to use `setPageContext` from
your JS.

### 6.6 Filament

Filament builds forms via a builder API. There is currently NO automatic
integration as with Backpack — use `@chatbotForm` in a custom Blade
panel or publish the schema via `setPageContext` from the panel's JS.
Backlog: explore a `FilamentPageContextProvider` analogous to the
Backpack one.

---

## 7. Known limitations

- **Blade parser is heuristic** (regex over `<form`). Cases where
  `<form` is split across multiple lines mixed with Blade conditionals
  may not be detected by `chatbot:scan-forms`. Common cases work fine.
- **Array-nested rules not supported** (`items.*.name`) in variant 1 —
  the LLM cannot fill array fields well at this time.
- **`@chatbotForm` does not wrap the `<form>`**: it must be placed AS
  an attribute inside the tag (like any other `class="..."`). This is
  intentional — Blade does not allow directives to generate attributes
  from outside the tag without an invasive preprocessor.

---

## 8. References

- Directive: `src/View/Directives/ChatbotFormDirective.php`
- Rules mapper: `src/Validation/RulesToFormSchema.php`
- Commands: `src/Console/Commands/ScanFormsCommand.php` + `IntegrateFormCommand.php`
- `fill_form` primitive: [`FRONTEND_TOOLS.md`](../FRONTEND_TOOLS.md)
- Backpack (comparison): [`integrations/backpack.md`](backpack.md)
- Write tools (combo): [`backend-tools.md`](../backend-tools.md)
