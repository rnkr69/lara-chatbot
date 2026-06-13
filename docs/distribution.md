# Package Distribution and Consumption

*English · [Español](distribution.es.md)*

This guide explains how to publish `rnkr69/lara-chatbot` to a private git repository and consume it from host projects, along with the recommended CI matrix for future releases.

> If you only want to install the package in an existing Laravel project, read the [Installation](#installation) section and return to the [README](../README.md). The rest of this document is intended for package maintainers.

---

## Installation

### Minimal recipe — VCS repository

A host with SSH access to the package's private repository can consume it by adding a `vcs` repository entry to its `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/rnkr69/lara-chatbot.git"
        }
    ],
    "require": {
        "rnkr69/lara-chatbot": "^1.0"
    }
}
```

Then:

```bash
composer update rnkr69/lara-chatbot
php artisan chatbot:install
```

> Replace `https://github.com/rnkr69/lara-chatbot.git` with the real repo URL. The recipe works with any git host (private GitHub, self-hosted GitLab, Bitbucket, Gitea…). Composer resolves tags and branches directly without needing an intermediate package server.

### If your company runs Satis, Packeton, or Private Packagist

For companies with multiple private packages, running a package server avoids declaring a `repositories.vcs` entry for each one and speeds up `composer update` (Composer no longer has to clone every repo just to read its `composer.json`).

#### Satis (free, static)

[Satis](https://github.com/composer/satis) generates a static `packages.json` from a list of VCS repos. It is served over HTTPS behind your VPN.

```json
{
    "repositories": [
        { "type": "composer", "url": "https://packages.example.com" }
    ],
    "require": { "rnkr69/lara-chatbot": "^1.0" }
}
```

#### Packeton (open-source, dynamic)

[Packeton](https://github.com/vtsykun/packeton) is a maintained fork of Packagist with a UI, access control, and zip mirroring. The better choice if you need to list packages and versions from a dashboard.

#### Private Packagist (SaaS)

[Private Packagist](https://packagist.com/) is the SaaS offering from the authors of Composer. Zero infrastructure, professional support, managed security policy.

> The package does not impose any preference. Any of the four mechanisms (direct VCS, Satis, Packeton, Private Packagist) fulfils the package contract: the host runs `composer update rnkr69/lara-chatbot` and receives the published version at the latest git tag.

---

## Versioning and releases

### Policy

`rnkr69/lara-chatbot` follows [SemVer 2.0](https://semver.org/spec/v2.0.0.html). The [`CHANGELOG.md`](../CHANGELOG.md) lists the public surface whose changes trigger a `MAJOR` bump. Summary:

| Change | Bump |
|---|---|
| New tool, new block renderer, new artisan command, new opt-in integration | MINOR |
| Internal refactor with no public-surface change | PATCH |
| Breaking change to HTTP, config keys, `BackendTool`/`FrontendTool` contracts, Web Component attributes, storage keys, or SSE events | MAJOR |

### Release process

1. Work on `main` or feature branches.
2. When a series of changes closes a milestone, update `CHANGELOG.md`:
   - Move `[Unreleased]` to a new `[X.Y.Z] - YYYY-MM-DD` entry.
   - Create a new empty `[Unreleased]` section at the top.
3. Commit `chore: release vX.Y.Z`.
4. Annotated git tag: `git tag -a vX.Y.Z -m "Release vX.Y.Z"` and `git push --follow-tags`.
5. Hosts run `composer update rnkr69/lara-chatbot` and receive the new tag.

### Branching

- `main` is the stable branch. It only receives merges from already-verified feature branches or hotfixes.
- There is no separate `develop` branch — the flow is trunk-based with short-lived branches.
- Hotfixes on published versions: branch `hotfix/vX.Y.Z+1` from the tag, apply fix, new tag. If `main` has already diverged, cherry-pick the fix to `main` as well.

---

## CI Pipeline

The package includes a reference GitHub Actions workflow at
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml). Companies
using another git host (GitLab CI, Bitbucket Pipelines, Gitea Actions,
Drone) translate the matrix and steps into their local YAML — the
commands documented below are the portable source of truth.

### Matrix

| Axis | CI values | Supported values |
|---|---|---|
| PHP | `8.2`, `8.3` | `8.2`, `8.3`, `8.4` |
| Laravel | `^11.0`, `^12.0` | `^11.0`, `^12.0` |

CI runs **4 combinations** PHP × Laravel (a subset of the 6 supported).
PHP 8.4 is validated manually before the release tag; it is left out of
CI to shorten wall-clock time and reduce Actions cost.

`composer.json` declares `"illuminate/contracts": "^11.0|^12.0"` and
`"illuminate/support": "^11.0|^12.0"`. `prism-php/prism: ^0.100`
requires PHP `^8.2`.

> **Laravel 10 is out of scope for this package.**
> If a host needs L10, upgrade first.

### Pipeline steps

Each matrix combination runs the following 4 steps:

#### 1. PHP lint + Pest suite

```bash
composer install --prefer-dist --no-interaction --no-progress
vendor/bin/pest --colors=always
```

Additionally, pre-commit-style syntax lint:

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
```

PHPStan is intentionally not included in this package (to keep `require-dev` light); if your CI already has PHPStan globally, you can add it optionally. The Pest suite covers the critical contracts.

#### 2. JS bundle build + budget verification + tokens

```bash
npm ci
npm run build
npm run build:check    # gzip size cap (independent)
npm run build:tokens   # feature token presence (independent)
```

The package produces **two separate bundles** (both in `public-build/`):

| Bundle | gzip cap | Purpose |
|---|---|---|
| `chatbot-widget.js` | **80 KB** | Floating widget (chat + suggested prompts + confirm banners + pin button). |
| `chatbot-dashboard.js` | **150 KB** | Personal Dashboard: gridstack + Chart.js default + KPI renderer + sidebar + DashboardApp. Only loaded at `/chatbot/dashboard`. |

`scripts/build.mjs` aborts the build (`process.exit(1)`) if **either** bundle exceeds its cap; the integrating host does not need a separate CI step to enforce it — `npm run build` already fails. `npm run build:check` runs the same gate post-build as an independent check.

**Token gate.** `scripts/build.mjs` also verifies, after compilation,
two classes of tokens in each bundle:

1. **`REQUIRED`** — string literals that each bundle must contain so
   that its features are not tree-shaken away.
2. **`SHARED`** — string literals that must appear in **both** bundles
   (cross-bundle protocol: `setPageContext`, `chatbot:ready`,
   `chatbot:dashboard-mutation`, `data-i18n`, `registerBlockRenderer`,
   `kpi`). This catches the class of bug where a fix is applied to the
   widget bundle but forgotten in the dashboard bundle. If a `SHARED`
   token appears in one bundle but is missing from the other, CI fails
   with "cross-bundle contract drift".

`npm run build` fails if either gate fails; `npm run build:tokens`
runs the same token gate independently. When a new feature crosses
the widget↔dashboard boundary, add its token to the `SHARED` array
in `scripts/check-bundle-tokens.mjs`.

If a host registers custom renderers or tools that pull heavy libraries into the dashboard bundle, the cap protects against inflated TTFB on `/chatbot/dashboard`. For overrides requiring additional libraries, consider registering them as dynamic ESM (lazy-loaded post-mount) rather than embedding them in the bundle.

> Current state: widget **~26 KB gzip / 80 KB cap** (~68% under budget) · dashboard **~108 KB gzip / 150 KB cap** (~28% under budget).

#### 3. Vitest suite + TypeScript typecheck

```bash
npm test          # Vitest
npm run typecheck # tsc --noEmit in strict mode
```

Covers Web Component logic, the dedicated page, cross-tab persistence, sanitizers, frontend primitives, and typed block modules.

#### 4. Playwright e2e suite (chromium-only) — run manually before release tag

```bash
npm run test:e2e
```

**Not included in CI** by deliberate choice: Playwright is slow on
WSL/runners and the Vitest suite (487 tests) + Pest already cover the
bulk of the protocol in isolation. Run it manually before each `git tag`.

Covers the critical widget flows (widget→page handoff, SPA navigation,
cross-tab localStorage) and the dashboard. The `pretest:e2e` script
automatically rebuilds the bundle to avoid running tests against a
stale bundle.

> `tests/e2e/dashboard.spec.ts` includes a **visual** regression test:
> it checks that the dashboard root computes to `display: grid`, that
> `<main>` has a readable width (> 400 px), and that pinned tables carry
> the block CSS (`border-collapse: collapse`, cell padding ≠ 1 px).
> These bugs are not caught by the functional suite — they only appear
> when rendered.

> If Playwright is added to CI in the future, cache `~/.cache/ms-playwright`
> (chromium) between runs — without the cache it adds ~1 min to the first run.

### Tying releases to git tags

The workflow should run all 4 steps on every push to `main` and on every PR. For releases (push of a `vX.Y.Z` tag), add an extra job that:

1. Verifies the tag points to a commit with a green CI run.
2. Creates release notes from the corresponding section of `CHANGELOG.md`.
3. (Optional) Notifies Satis/Packeton/Private Packagist if the company uses them via webhook.

Composer understands git tags directly — a host can run `composer require rnkr69/lara-chatbot:^0.4` as soon as the tag is pushed, without waiting for a package server.

---

## FAQ

**Why do you include a `.github/workflows/ci.yml` if the real git host may not be GitHub?**
As an executable reference. The first two pilot integrations will run against GitHub, so the workflow works out of the box there. Companies on another git host (GitLab CI, Bitbucket, Gitea, Drone) translate the YAML steps — they are the same four: composer install + pest, npm install + typecheck + vitest + build. The matrix and commands in this guide are the portable source of truth; the YAML is the reference implementation for GitHub.

**How do I test the `composer require` recipe locally before publishing a tag?**
Use `dev-main` while the branch is in development:
```json
"require": { "rnkr69/lara-chatbot": "dev-main" }
```
Composer will accept the `main` branch with `minimum-stability: dev`. To test a candidate tag without pushing it to a public repo, one option is `--repository '{"type":"path","url":"../chatbot"}'` pointing at your local working copy.

**What if the host is on Laravel 10?**
Not supported. Hosts on L10 must upgrade to L11 or higher to use this package.
