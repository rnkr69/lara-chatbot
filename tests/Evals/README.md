# Eval harness

*English · [Español](README.es.md)*

Minimal viable suite for measuring the LLM's tool-calling quality.
Lives separately from `tests/Feature` so it can be easily dropped from the
fast CI if it grows, and to signal that it is a **functional** suite distinct
from traditional unit testing.

## What it measures

For each YAML fixture, it validates three binary invariants:

1. **Tool selection** — was the expected tool called (or none, if the
   fixture declares so)?
2. **Args correctness** — the invocation args contain at least the
   keys/values declared in `args_subset`.
3. **Multi-tool sequence** *(optional, only when the fixture sets
   `match_sequence: true`)* — the sequence of tool calls matches
   the expected list exactly.

An invocation of a `forbidden_tools` entry fails the fixture.

## Two modes

### Fake mode (default — runs in CI)

```bash
vendor/bin/pest --testsuite=Evals
```

Stages `Prism::fake()` with the pre-cooked `expected.tool_calls` and
verifies that **the orchestrator processes them correctly** (authorization
cascade, SSE sequence, args forwarding). Deterministic, fast
(~10 s for 8 fixtures), no token cost.

**What this mode does NOT validate**: the ability of the real LLM to choose
the correct tool and fill in args properly. That is live mode.

### Live mode (opt-in — manual, optional on scheduled CI)

```bash
CHATBOT_EVAL_LIVE=1 \
ANTHROPIC_API_KEY=sk-ant-... \
vendor/bin/pest --testsuite=Evals
```

Optional override variables:

| Var | Default | Notes |
|---|---|---|
| `CHATBOT_EVAL_LIVE` | (off) | `1` / `true` enables live mode. |
| `CHATBOT_EVAL_LIVE_PROVIDER` | `config('chatbot.provider')` | `anthropic`, `openai`, `groq`, etc. |
| `CHATBOT_EVAL_LIVE_MODEL` | `config('chatbot.model')` | Specific model. For A/B between `claude-sonnet-4-6`, `claude-haiku-4-5`, `gpt-4o`, etc. |
| `ANTHROPIC_API_KEY` / equivalent | — | Provider credential. Fails loudly if missing. |

In live mode the harness does **NOT** stage `Prism::fake`. For each fixture:

1. Registers the tools from `tools_available` in the `ToolRegistry`.
2. Invokes `ChatService::handle()` with the fixture's `user_message` + `page_context`.
3. The real LLM decides which tool to call (based on the catalog descriptions).
4. The orchestrator executes the tool (stubs do nothing domain-specific,
   they only record the invocation and return `ok`).
5. The actual `tool_calls` are compared against `expected.tool_calls`.

**Estimated cost**: ~5K-10K tokens per fixture against Anthropic Sonnet
(prompt + tool catalog + response). 8 fixtures ≈ 50-80K tokens ≈ $0.15-0.25
per full run. Scales linearly with the number of fixtures.

#### Per-fixture trace

Each fixture dumps its result to `tests/Evals/last-live-run.json`
(gitignored). Structure:

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

To inspect:

```bash
# Summary
cat tests/Evals/last-live-run.json | jq .summary

# Only failed fixtures
cat tests/Evals/last-live-run.json | jq '.entries[] | select(.pass == false)'

# See what the LLM chose for a specific fixture
cat tests/Evals/last-live-run.json | jq '.entries[] | select(.fixture == "08_dashboard_context.yaml") | .actual'
```

#### Scheduled CI (optional)

Live mode is deliberately **excluded from the fast CI** (`.github/workflows/ci.yml`)
— cost per PR + LLM non-determinism. For regular visibility, you can wire it
into a scheduled (cron) CI job that runs the `Evals` suite in live mode and
publishes `last-live-run.json` as an artifact (or sends it wherever you need).

**Relevant environment variables**:

| Var | Default | Notes |
|---|---|---|
| `ANTHROPIC_API_KEY` (or provider equivalent) | — | The real LLM call. |
| `EVAL_ALERT_THRESHOLD` | `0.80` | Accuracy threshold below which the run turns red. |
| `CHATBOT_EVAL_LIVE_PROVIDER` | `config('chatbot.provider')` | Provider override without touching config. |
| `CHATBOT_EVAL_LIVE_MODEL` | `config('chatbot.model')` | Model override. |

## Fixture format

```yaml
name: "Short human-readable name"
description: |
  Multi-line explanation of what this fixture tries to cover and which
  invariant it kills if broken.

user_message: "what the user types in the chat"
page_context:
  route: "/admin/orders"
  dashboard: { slug: my-dash, name: My Dash, is_default: true, widgets: [] }

tools_available:
  - name: list_my_invoices
    description: "List the user invoices."
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

## Adding a new fixture

Whenever a shipped bug reveals a gap in LLM coverage (it picked the wrong
tool / hallucinated args / failed to chain two calls that needed chaining),
add a fixture that captures it. The count grows organically; 8 is the
defensible minimum, not the ceiling.

Naming: `NN_short_name.yaml` (ordinal so `sort()` keeps a stable order
in the Pest dataset).
