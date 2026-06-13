# Integration · Custom forms (no-Backpack)

*[English](custom-forms.md) · Español*

> Cómo integrar `fill_form` con `<form>` que no vienen de Backpack
> (Blade plano, Livewire, Inertia, Filament — cualquier stack).
>
> Pre-lectura: [`page-context.es.md`](../page-context.es.md) +
> [`FRONTEND_TOOLS.es.md`](../FRONTEND_TOOLS.es.md). Para hosts que SÍ usan
> Backpack la integración es out-of-the-box — ver
> [`integrations/backpack.es.md`](backpack.es.md).

---

## 1. ¿Por qué este doc?

`BackpackPageContextProvider` introspecta el `CrudPanel` para emitir el
schema del form (campos, types, options). Cualquier app Laravel
"estándar" no tiene ese mecanismo: el `<form>` vive en una view Blade
plana / Livewire component / Inertia page, y sin ayuda del integrador el
LLM no sabe qué `name` HTML tiene cada control ni qué values esperan
los selects.

El package ofrece tres piezas para cerrar ese gap:

| Pieza | Qué hace |
|---|---|
| `@chatbotForm` Blade directive | tagea el `<form>` y publica su schema al page context |
| `chatbot:scan-forms` | auditoría: lista forms taggeados vs untagged |
| `chatbot:integrate-form` | wizard interactivo que añade `@chatbotForm` a un form concreto |

Con eso, el LLM ve el schema del form en `## Current page` y puede
llamar `fill_form` con `name` y `value` correctos sin guessing.

---

## 2. La directiva `@chatbotForm`

### 2.1 Sintaxis

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

El primer argumento es el **id** que el LLM usará en `form_id`. El
segundo (opcional) es el **source** del schema. Tres variantes
soportadas:

### 2.2 Variante 1 — FormRequest FQCN (recomendada)

```blade
<form method="post" action="{{ route('contact.store') }}"
      @chatbotForm('contact-form', App\Http\Requests\ContactRequest::class)>
    ...
</form>
```

La directiva instancia el FormRequest, lee `rules()` y mapea cada regla
a un schema field con `Rnkr69\LaraChatbot\Validation\RulesToFormSchema`.
Soporta:

| Regla Laravel | Schema generado |
|---|---|
| `'required'` | `required: true` |
| `'email'` | `type: 'email'` |
| `'integer'` / `'numeric'` | `type: 'number'` |
| `'boolean'` | `type: 'boolean'` |
| `'date'` | `type: 'date'` |
| `'date_format:Y-m-d H:i'` | `type: 'datetime'` |
| `'url'` | `type: 'url'` |
| `'file'` / `'image'` | `type: 'file'` |
| `'in:a,b,c'` o `Rule::in([...])` | `type: 'select'`, `options: ['a','b','c']` |
| `'max:255'` / `'min:1'` | `max: 255` / `min: 1` |

Si el FormRequest implementa `attributes()` (labels amigables), la
directiva los usa como `label`:

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

→ el schema emite `{name: 'email', label: 'Tu correo electrónico', type: 'email', required: true}`.

**Ventaja**: las rules son la single source of truth — cuando cambias
la validación, el schema del LLM se actualiza solo. **Edge case**:
rules array-nested (`items.*.name`) se ignoran (el LLM no sabe rellenar
array fields así de cleanly). Hosts con forms array-heavy deben caer a
variante 2 o ampliar el mapper.

### 2.3 Variante 2 — Lista inline

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

La forma **más simple y predecible**. Útil cuando:

- No hay FormRequest (el form va directo al controller).
- Quieres exponer options custom que no salen de rules (e.g. labels
  amigables para enums).
- Tu form tiene campos que NO mapean 1:1 con rules (un hidden, un
  campo computado).

### 2.4 Variante 3 — Runtime-extracted (sin schema)

```blade
<form method="post" action="..." @chatbotForm('contact-form')>
    @csrf
    <input name="name" required>
    <input name="email" type="email" required>
    ...
</form>
```

Sin segundo argumento. La directiva emite el atributo
`data-chatbot-form` + un `<script>` ligero que, al boot del widget,
escanea el form y publica `{name, type, required}` extraídos del DOM
via `Chatbot.setPageContext({form: {...}})`.

Schema más pobre (sin labels amigables, sin options de selects), pero
útil como punto de partida cuando no quieres tocar nada del código
existente. Migra a variante 1 o 2 cuando la UX te lo pida.

---

## 3. Aliases con `data-chatbot-field`

Para fields con `name` HTML feo (`metadata[options][0][value]`,
`payload[items][3][qty]`), expón un alias amigable que el LLM pueda usar:

```html
<input name="metadata[options][0][value]" data-chatbot-field="first_option">
```

El LLM ve `first_option` en el schema del page context (cuando lo
declares en variante 1/2) y `fillForm` busca por
`[data-chatbot-field]` antes que por `[name]`. Si el LLM llama con un
name que no existe, el warn de "field not found" lista AMBOS conjuntos
(`name` y `data-chatbot-field`) para diagnóstico.

---

## 4. Combinarlo con una write tool

A veces quieres que el LLM también pueda **enviar** el form sin que el
usuario esté en `/contact`. Patrón:

1. Mantén `@chatbotForm('contact-form', ContactRequest::class)` en la
   view (para que cuando el usuario sí esté en la página el LLM
   prerellene el form).
2. Crea una backend write tool `submit_contact_form` que valida con
   `ContactRequest::rules()` (ver
   [`backend-tools.es.md`](../backend-tools.es.md)) y persiste.
3. El LLM decide ruta a ruta: si el usuario está en `/contact` →
   `fill_form` (mejor UX, usuario revisa); si está en otra página →
   `submit_contact_form` directo.

Los dos sitios consumen la MISMA clase de rules — sin drift entre
front (lo que ve el LLM) y back (lo que valida server-side).

---

## 5. Comandos de soporte

### 5.1 `chatbot:scan-forms` — auditoría

```
php artisan chatbot:scan-forms
```

Escanea `resources/views/**/*.blade.php` y reporta cada `<form>` con su
estado:

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
- `--path=resources/views/admin` para escanear sólo un subdirectorio.
- `--json` para salida programática.

Es **read-only** — no modifica nada.

### 5.2 `chatbot:integrate-form <view>` — wizard

```
php artisan chatbot:integrate-form resources/views/contact.blade.php
```

Localiza el primer `<form>` del Blade, te pregunta:
- Qué id usar (default: slug del basename).
- Qué schema source (FormRequest / lista manual / runtime-extracted).
- Muestra el diff antes de aplicar.

Es **idempotente**: si el form ya tiene `@chatbotForm` o
`data-chatbot-form`, skip con mensaje (usa `--force` para sobrescribir).

Flags útiles:
- `--request=App\Http\Requests\ContactRequest` para no-interactividad.
- `--id=contact-main` para forzar un id concreto.

---

## 6. Casos edge

### 6.1 Forms con `_method=PUT/DELETE`

El LLM no se entera del verb HTTP porque sólo ve el schema de campos.
Eso es OK — `fill_form` no submitea por defecto (`submit: false`). Si
submitea (`submit: true`), el `<form>` ya emite el `_method` correcto
porque Laravel inyecta el hidden via `@method`. Sin acción adicional.

### 6.2 Forms con file uploads (`multipart/form-data`)

`fill_form` rellena `<input type="file">` con `''` (vacío) si el LLM
pasa un value — no hay forma de subir un archivo desde la conversación
por v1.1.x. Si quieres que el LLM "envíe" un archivo, considera una
write tool específica que reciba un URL y haga el upload server-side.

### 6.3 Forms multi-step (wizard)

Cada step debería ser un `<form>` independiente con su propio
`@chatbotForm`. El LLM ve el form del step actual via page context y
puede rellenarlo. Al avanzar de step, el host emite
`chatbot:context-changed` (manualmente vía `setPageContext` o vía
re-render) para refrescar el schema.

### 6.4 Livewire 3.x

`wire:model` escucha eventos `input`. Como `fillForm` ya dispara
`input` y `change` desde v1.0.1, Livewire reacciona normalmente. No
hace falta configuración extra. Verifica que el `<form>` (o
`<div wire:submit="save">`) tenga `data-chatbot-form` para que
`fill_form` lo localice — Livewire NO emite forms con `id` por
defecto.

### 6.5 Inertia

Los forms Inertia son JSX/Vue/Svelte renderizados client-side. El
patrón es:
1. En el Blade root layout (`app.blade.php`), expón el schema via
   `<meta name="chatbot:context-form" content='...'>` o vía
   `Chatbot.setPageContext({form: ...})` en el componente cliente.
2. Asegúrate de que el `<form>` final renderizado tenga
   `data-chatbot-form="..."`. Para Vue/React, ponlo como prop o
   attribute directo en el `<form>`.

La directiva `@chatbotForm` no llega a un `<form>` que renderiza el
framework cliente — tendrías que usar `setPageContext` desde tu JS.

### 6.6 Filament

Filament construye forms via builder API. Hoy NO hay integración
automática como con Backpack — usa `@chatbotForm` en el panel Blade
custom o publica el schema via `setPageContext` desde el JS del panel.
Backlog: explorar un `FilamentPageContextProvider` análogo al de
Backpack.

---

## 7. Limitaciones conocidas

- **Parser Blade es heurístico** (regex sobre `<form`). Casos con
  `<form` partido en varias líneas mezclado con condicionales Blade
  pueden no detectarse en `chatbot:scan-forms`. Lo común sí.
- **No soporta forms array-nested rules** (`items.*.name`) en variante
  1 — el LLM no puede rellenar bien array fields de momento.
- **`@chatbotForm` no envuelve el `<form>`**: hay que ponerlo COMO
  atributo dentro del tag (como cualquier otro `class="..."`). Esto es
  intencional — Blade no permite directivas que generen atributos
  desde fuera del tag sin un preprocessor invasivo.

---

## 8. Referencias

- Directiva: `src/View/Directives/ChatbotFormDirective.php`
- Mapper rules: `src/Validation/RulesToFormSchema.php`
- Comandos: `src/Console/Commands/ScanFormsCommand.php` + `IntegrateFormCommand.php`
- `fill_form` primitive: [`FRONTEND_TOOLS.es.md`](../FRONTEND_TOOLS.es.md)
- Backpack (comparativa): [`integrations/backpack.es.md`](backpack.es.md)
- Write tools (combo): [`backend-tools.es.md`](../backend-tools.es.md)
