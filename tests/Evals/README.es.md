# Eval harness

*[English](README.md) · Español*

Suite mínima viable para medir la calidad del tool-calling del LLM.
Vive aparte de `tests/Feature` para que sea fácil sacarla del CI rápido si
crece, y para señalar que es una suite **funcional** distinta del unit
testing tradicional.

## Qué mide

Para cada fixture YAML, valida tres invariantes binarias:

1. **Tool selection** — ¿se llamó la tool esperada (o ninguna, si la
   fixture lo declara)?
2. **Args correctness** — los args de la invocación contienen al menos las
   claves/valores declarados en `args_subset`.
3. **Multi-tool sequence** *(opcional, sólo cuando la fixture pone
   `match_sequence: true`)* — la secuencia de tools llamadas coincide
   exactamente con la lista esperada.

Una invocación a una `forbidden_tools` falla la fixture.

## Dos modos

### Modo fake (default — corre en CI)

```bash
vendor/bin/pest --testsuite=Evals
```

Stagea `Prism::fake()` con los `expected.tool_calls` ya cocinados y
verifica que **el orquestador los procesa correctamente** (cascada de
autorización, secuencia SSE, forwarding de args). Determinista, rápido
(~10 s las 8 fixtures), sin coste de tokens.

**Qué NO valida** en este modo: la capacidad del LLM real de elegir la
tool correcta y rellenar args bien. Eso es modo live.

### Modo live (opt-in — manual, opcional en CI scheduled)

```bash
CHATBOT_EVAL_LIVE=1 \
ANTHROPIC_API_KEY=sk-ant-... \
vendor/bin/pest --testsuite=Evals
```

Variables opcionales para override:

| Var | Default | Notas |
|---|---|---|
| `CHATBOT_EVAL_LIVE` | (off) | `1` / `true` activa el modo live. |
| `CHATBOT_EVAL_LIVE_PROVIDER` | `config('chatbot.provider')` | `anthropic`, `openai`, `groq`, etc. |
| `CHATBOT_EVAL_LIVE_MODEL` | `config('chatbot.model')` | Modelo concreto. Para A/B entre `claude-sonnet-4-6`, `claude-haiku-4-5`, `gpt-4o`, etc. |
| `ANTHROPIC_API_KEY` / equivalente | — | Credencial del provider. Falla ruidoso si falta. |

En live mode el harness **NO stagea** `Prism::fake`. Cada fixture:

1. Registra las tools de `tools_available` en el `ToolRegistry`.
2. Invoca `ChatService::handle()` con `user_message` + `page_context` de
   la fixture.
3. El LLM real decide qué tool llamar (basándose en las descripciones
   del catálogo).
4. El orquestador ejecuta la tool (las stubs no hacen nada de dominio,
   sólo registran la invocación y devuelven `ok`).
5. Comparamos los `tool_calls` reales contra `expected.tool_calls`.

**Coste estimado**: ~5K-10K tokens por fixture contra Anthropic Sonnet
(prompt + tool catalog + response). 8 fixtures ≈ 50-80K tokens ≈ $0.15-0.25
por run completo. Sube linealmente con número de fixtures.

#### Trace por fixture

Cada fixture vuelca su resultado a `tests/Evals/last-live-run.json`
(gitignored). Estructura:

```json
{
  "run_id": "eval_abc123",
  "started_at": "2026-05-16T14:30:00+00:00",
  "summary": {
    "total": 8, "passed": 7, "failed": 1, "errored": 0,
    "accuracy": 0.875
  },
  "entries": [
    {
      "fixture": "01_list_invoices.yaml",
      "name": "list_invoices basic — single tool, single arg",
      "pass": true,
      "reason": null,
      "error": null,
      "provider": "anthropic",
      "model": "claude-sonnet-4-6",
      "latency_ms": 1432,
      "tokens": { "prompt_tokens": 412, "completion_tokens": 28 },
      "expected": { "tool_calls": [...], "forbidden_tools": [...] },
      "actual": {
        "tool_calls": ["list_my_invoices"],
        "tool_calls_args": { "list_my_invoices": [{"limit": 20}] },
        "assistant_text": "Aquí tienes tus facturas..."
      }
    },
    ...
  ]
}
```

Para inspeccionar:

```bash
# Resumen
cat tests/Evals/last-live-run.json | jq .summary

# Sólo fixtures que fallaron
cat tests/Evals/last-live-run.json | jq '.entries[] | select(.pass == false)'

# Ver qué eligió el LLM en una fixture concreta
cat tests/Evals/last-live-run.json | jq '.entries[] | select(.fixture == "08_dashboard_context.yaml") | .actual'
```

#### CI scheduled (opcional)

Modo live deliberadamente **excluido del CI rápido** (`.github/workflows/ci.yml`)
— coste por PR + no determinismo del LLM. Si quieres visibilidad regular, puedes
wirearlo en un job programado (cron) de tu CI que corra la suite `Evals` en modo
live y publique el `last-live-run.json` como artifact (o lo envíe a donde quieras).

**Variables de entorno relevantes**:

| Var | Default | Notas |
|---|---|---|
| `ANTHROPIC_API_KEY` (o equivalente del provider) | — | La llamada LLM real. |
| `EVAL_ALERT_THRESHOLD` | `0.80` | Umbral de accuracy bajo el cual la run sale roja. |
| `CHATBOT_EVAL_LIVE_PROVIDER` | `config('chatbot.provider')` | Override del provider sin tocar config. |
| `CHATBOT_EVAL_LIVE_MODEL` | `config('chatbot.model')` | Override del modelo. |

## Formato de fixture

```yaml
name: "Nombre humano corto"
description: |
  Multi-line explicación de qué intenta cubrir esta fixture y qué
  invariante mata si se rompe.

user_message: "lo que el usuario escribe en el chat"
page_context:
  route: "/admin/orders"
  dashboard: { slug: my-dash, name: My Dash, is_default: true, widgets: [] }

tools_available:
  - name: list_my_invoices
    description: "Lista las facturas del usuario."
    parameters:
      type: object
      properties:
        status: { type: string, enum: [paid, pending, overdue] }
      required: []

expected:
  tool_calls:
    - name: list_my_invoices
      args_subset:
        status: overdue        # subset, no es match exacto
  forbidden_tools:
    - search_orders            # si aparece, falla
  match_sequence: true         # opcional, default false
```

## Añadir una fixture nueva

Cada vez que un bug shipped revele un hueco de cobertura del LLM
(seleccionó la tool equivocada / inventó args / no encadenó dos llamadas
que tenía que encadenar), añadir una fixture que lo capture. El número
crece orgánicamente; 8 es el mínimo defendible, no el techo.

Naming: `NN_short_name.yaml` (ordinal para que `sort()` mantenga orden
estable en el dataset Pest).
