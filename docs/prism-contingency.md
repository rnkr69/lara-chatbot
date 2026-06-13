# Plan B: What happens if Prism dies?

*English · [Español](prism-contingency.es.md)*

`rnkr69/lara-chatbot` uses [`prism-php/prism`](https://github.com/prism-php/prism)
as the LLM abstraction layer. This is deliberate and convenient — a
host can switch from Anthropic to OpenAI to Ollama by editing a single
config line — but it introduces a load-bearing dependency on a package
that is still pre-`1.0`.

This document answers the question "what do we do if Prism stops being
maintained or releases a v1 with a breaking change that breaks our code?".

---

## Why we depend on Prism

- **Free multi-provider support.** Anthropic, OpenAI, Groq, Gemini, Mistral,
  Ollama behind the same API. Building this in-house takes months.
- **Streaming with a unified shape.** `TextDeltaEvent`,
  `ToolCallEvent`, `ToolResultEvent`, `StreamEndEvent` events with the same
  semantics across all providers.
- **Tool calling with schema normalisation.** Providers differ in how
  they express tools and args; Prism unifies them.
- **Idiomatic Laravel.** `Prism::fake()`, facade, container bindings —
  the package tests (especially
  `tests/Feature/Llm/LlmGatewayTest.php` and
  `tests/Feature/Services/ChatServiceTest.php`) rely on this.

---

## Prism status (as of this doc — 2026-05-16)

- Installed version: `^0.100`. Pre-`1.0`. Same flag colour as us.
- Maintainership: active community, regular releases (roughly weekly).
  No visible corporate entity behind it.
- Low risk in the short term. Medium risk at 12-24 months (most
  pre-1.0 Laravel packages survive, but some do not — and we already
  had to sidestep one [`consoletvs/charts`] that did die).

Recommended action: the package maintainer reviews Prism activity at
each package release tag (roughly monthly). If there are ≥3 months
without merges or responses to open issues, activate Plan B.

---

## Plan B: direct client

### What would need to happen

Replace Prism with the official SDK of the active provider for each
host (the majority today: the Anthropic SDK). This means:

1. Rewrite `Rnkr69\LaraChatbot\Llm\LlmGateway` to talk directly to the
   provider SDK (HTTP via the official client).
2. Maintain the event shape that `ChatService` consumes today
   (`TextDeltaEvent`, `ToolCallEvent`, `ToolResultEvent`, `StreamEndEvent`)
   by building them manually from the provider stream.
3. Replace `Prism::fake()` in the ~10 tests that use it with an
   equivalent mock of the SDK's HTTP client (Mockery, or the SDK's own
   testing helper if one exists).
4. Lose free multi-provider support. Supporting a second provider
   requires repeating steps 1-3 against its SDK.

### Estimated effort

- Gateway rewrite against the Anthropic SDK: **3-5 person-days**.
  The abstraction already exists (`LlmGateway`); only its implementation
  changes.
- Tests adapted: **1-2 person-days**.
- Coverage of the 6 current providers: **3-4 person-weeks**
  (typically not needed — a host submits a PR only for the
  providers it actually uses).

### What we would lose

- **Free multi-provider support.** Switching Anthropic→OpenAI would no
  longer be a one-line config change but a PR.
- **MCP bridge** (which lives on top of `prism-php/relay`) — it would
  have to be reimplemented from the MCP spec or uninstalled. Since no
  currently integrated host uses MCP (it is experimental), this can
  be deferred.
- **Tool schema normalisation.** We would have to maintain this
  ourselves if we ever want to support more than one provider.

---

## Activation trigger

Activate Plan B if **one or more** of the following apply:

1. Prism goes **≥3 months without merges** or responses to open issues.
2. Prism releases **v1.x with a breaking change** that breaks our
   `LlmGateway` and the upgrade requires ≥2 weeks of work (at that
   point, evaluate whether rewriting against the direct SDK is more
   worthwhile).
3. The provider most hosts use (Anthropic today) ships a new capability
   that Prism takes **≥1 month** to support and it blocks something a
   host has already requested.
4. A security analysis detects a **vulnerability in Prism** with no
   available patch and we need to remove the dependency immediately.

Until then: stay on Prism, keep broad test coverage (`Prism::fake()`
covers the usage surface), and review its status at each tag cut.

---

## What IS already done today

- `LlmGateway` is the package's own contract — the rest of the code
  imports nothing from Prism directly, only the gateway. This means
  the substitution is local to a single file.
- Orchestrator tests (`ChatServiceTest`, `EvalRunnerTest`) use
  `Prism::fake()` — when we replace Prism those mocks will need to
  change, but the behavioural coverage of the orchestrator remains
  valid (the contract does not change).
- Prism version pinned to `^0.100` in `composer.json` to prevent an
  automatic upgrade to a potentially disruptive v1. When Prism ships a
  stable v1, evaluate the upgrade before bumping.

---

## TL;DR

Plan B exists, is documented, is executable in a week, and the package
architecture supports it without a massive rewrite. You do not need to
execute it — you just need to show the tech lead that it exists.
