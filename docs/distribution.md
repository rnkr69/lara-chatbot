# Distribución y consumo del paquete

Esta guía explica cómo publicar `rnkr69/lara-chatbot` en un git privado y consumirlo desde proyectos host, además de la matriz CI recomendada para futuras releases.

> **Nota sobre versiones**: este documento contiene referencias a milestones
> internos pre-0.4 (`E\d+`, `v1.x`, `v2.x`) que no son releases públicas.
> La release actual es `0.4.0`. Los ejemplos de comandos en esta guía han
> sido normalizados a `^0.4`.

> Si solo quieres instalar el paquete en un proyecto Laravel existente, lee la sección [Instalación](#instalación) y vuelve al [README](../README.md). El resto del documento está pensado para mantenedores del paquete.

---

## Instalación

### Receta mínima — repositorio VCS

Un host con acceso SSH al repo privado del paquete puede consumirlo añadiendo un repositorio `vcs` en su `composer.json`:

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

Después:

```bash
composer update rnkr69/lara-chatbot
php artisan chatbot:install
```

> Sustituye `https://github.com/rnkr69/lara-chatbot.git` por la URL real del repo. La receta funciona con cualquier git host (GitHub privado, GitLab self-hosted, Bitbucket, Gitea…). Composer resuelve tags y branches directamente sin necesidad de servidor de paquetes intermedio.

### Si tu empresa tiene Satis, Packeton o Private Packagist

Para empresas con varios paquetes privados, montar un servidor de paquetes evita declarar un `repositories.vcs` por cada uno y acelera `composer update` (Composer ya no clona cada repo solo para leer su `composer.json`).

#### Satis (gratis, estático)

[Satis](https://github.com/composer/satis) genera un `packages.json` estático a partir de una lista de repos VCS. Se sirve por HTTPS detrás de la VPN.

```json
{
    "repositories": [
        { "type": "composer", "url": "https://packages.example.com" }
    ],
    "require": { "rnkr69/lara-chatbot": "^1.0" }
}
```

#### Packeton (open-source, dinámico)

[Packeton](https://github.com/vtsykun/packeton) es un fork mantenido de Packagist con UI, control de acceso y mirror de zip. Mejor opción si necesitas listar paquetes/versiones desde un dashboard.

#### Private Packagist (SaaS)

[Private Packagist](https://packagist.com/) es la oferta SaaS de los autores de Composer. Cero infra, soporte profesional, política de seguridad gestionada.

> El paquete no impone ninguna preferencia. Cualquiera de los cuatro mecanismos (VCS directo, Satis, Packeton, Private Packagist) cumple el contrato del paquete: el host hace `composer update rnkr69/lara-chatbot` y recibe la versión publicada con el último tag git.

---

## Versionado y releases

### Política

`rnkr69/lara-chatbot` sigue [SemVer 2.0](https://semver.org/spec/v2.0.0.html). El [`CHANGELOG.md`](../CHANGELOG.md) lista la superficie pública cuyos cambios son `MAJOR`. Resumen:

| Cambio | Bump |
|---|---|
| Nuevo tool, nuevo block renderer, nuevo comando artisan, nueva integración opt-in | MINOR |
| Refactor interno sin cambio en superficie pública | PATCH |
| Cambio incompatible en HTTP, config keys, contratos `BackendTool`/`FrontendTool`, atributos del Web Component, claves de storage o eventos SSE | MAJOR |

### Proceso de release

1. Trabajo en `main` o feature branches.
2. Cuando una serie de cambios cierra un hito, actualizar `CHANGELOG.md`:
   - Mover `[Unreleased]` a una nueva versión `[X.Y.Z] - YYYY-MM-DD`.
   - Crear un nuevo `[Unreleased]` vacío en la cabecera.
3. Commit `chore: release vX.Y.Z`.
4. Tag git anotado: `git tag -a vX.Y.Z -m "Release vX.Y.Z"` y `git push --follow-tags`.
5. Hosts hacen `composer update rnkr69/lara-chatbot` y reciben el nuevo tag.

### Branching

- `main` es la rama estable. Sólo recibe merge desde feature branches o hotfixes ya verificados.
- No hay rama `develop` separada — el flujo es trunk-based con branches cortas.
- Hotfixes sobre versiones publicadas: branch `hotfix/vX.Y.Z+1` desde el tag, fix, tag nuevo. Si la rama `main` ya divergió, hacer cherry-pick del fix a `main` también.

---

## Pipeline CI

El paquete incluye un workflow GitHub Actions de referencia en
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml). Empresas
con otro git host (GitLab CI, Bitbucket Pipelines, Gitea Actions,
Drone) traducen la matriz y los pasos a su YAML local — los comandos
documentados abajo son la fuente de verdad portable.

### Matriz

| Eje | Valores en CI | Valores soportados |
|---|---|---|
| PHP | `8.2`, `8.3` | `8.2`, `8.3`, `8.4` |
| Laravel | `^11.0`, `^12.0` | `^11.0`, `^12.0` |

CI corre **4 combinaciones** PHP × Laravel (subset de las 6 soportadas).
PHP 8.4 se valida manualmente antes de release tag; en CI lo dejamos
fuera para acortar el wall-clock y bajar coste de Actions.

`composer.json` declara `"illuminate/contracts": "^11.0|^12.0"` y
`"illuminate/support": "^11.0|^12.0"`. `prism-php/prism: ^0.100`
requiere PHP `^8.2`.

> **Laravel 10 fuera del paquete** (decisión D1).
> Si un host necesita L10, primero sube de versión.

### Pasos del pipeline

Cada combinación de la matriz ejecuta los 4 pasos siguientes:

#### 1. Lint PHP + suite Pest

```bash
composer install --prefer-dist --no-interaction --no-progress
vendor/bin/pest --colors=always
```

Adicionalmente, lint sintáctico estilo precommit:

```bash
find src -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
```

PHPStan no está incluido en este paquete por elección (mantener `require-dev` ligero); si tu CI ya tiene PHPStan global, puedes añadirlo opcionalmente. La suite Pest cubre los contratos críticos.

#### 2. Build de los bundles JS + verificación de budget + tokens

```bash
npm ci
npm run build
npm run build:check    # cap de tamaño gzip (independiente)
npm run build:tokens   # presencia de tokens de feature (independiente)
```

El paquete produce **dos bundles** separados (ambos en `public-build/`):

| Bundle | Cap gzip | Propósito |
|---|---|---|
| `chatbot-widget.js` | **80 KB** | Widget flotante (chat + suggested prompts + confirm banners + pin button). Cap desde v1.0 / E12. |
| `chatbot-dashboard.js` | **150 KB** | Personal Dashboard (v2.0 / E5+): gridstack + Chart.js default + KPI renderer + sidebar + DashboardApp. Sólo carga en `/chatbot/dashboard`. |

`scripts/build.mjs` revienta el build (`process.exit(1)`) si **cualquiera** de los dos excede su cap; el host integrador no necesita un paso CI específico para enforcearlo — el `npm run build` ya falla. `npm run build:check` ejecuta el mismo gate post-build como verificación independiente.

**Gate de tokens.** `scripts/build.mjs` también verifica, tras compilar,
dos clases de tokens en cada bundle:

1. **`REQUIRED`** — string-literals que cada bundle debe contener para que
   sus features no estén tree-shaken (pre-0.4 finding #4: la pin button no
   montaba porque su code-path SSE-metadata se cayó del bundle).
2. **`SHARED`** — string-literals que deben aparecer en **ambos** bundles
   (cross-bundle protocol: `setPageContext`, `chatbot:ready`,
   `chatbot:dashboard-mutation`, `data-i18n`, `registerBlockRenderer`,
   `kpi`). Esto caza la clase de bug PR-C (v2.2.2→v2.2.3 mismo día): un
   fix aplicado al widget bundle pero olvidado en el dashboard bundle. Si
   un token de `SHARED` aparece en uno y falta en otro, CI falla con
   "cross-bundle contract drift".

`npm run build` falla si falla cualquiera de los dos; `npm run build:tokens`
corre el mismo gate de forma independiente. Cuando una feature nueva
cruza la frontera widget↔dashboard, añadir su token al array `SHARED`
en `scripts/check-bundle-tokens.mjs`.

Si un host registra renderers propios o tools que tiran lib pesada al bundle del dashboard, el cap protege contra TTFB inflado de `/chatbot/dashboard`. Para overrides que requieran libs adicionales, considere registrarlas como ESM dinámico (lazy load post-mount) en vez de embedded en el bundle.

> Estado al cierre de E10 (v2.0): widget **~26 KB gzip / 80 KB cap** (~68% bajo budget) · dashboard **~108 KB gzip / 150 KB cap** (~28% bajo budget).

#### 3. Suite Vitest + typecheck TypeScript

```bash
npm test          # Vitest
npm run typecheck # tsc --noEmit en modo strict
```

Cubre la lógica del Web Component, página dedicada, persistencia cross-tab, sanitizers, primitivas frontend y módulos de bloques tipados.

#### 4. Suite Playwright e2e (chromium-only) — manual antes de release tag

```bash
npm run test:e2e
```

**No incluida en CI** por decisión deliberada: Playwright es lento sobre
WSL/runners y la suite Vitest (487 tests) + Pest ya cubren la mayor parte
del protocolo en isolation. Correrla a mano antes de cada `git tag`.

Cubre los flujos críticos del widget (handoff widget→página, navegación
SPA, cross-tab localStorage) y del dashboard. El script `pretest:e2e`
rebuilda el bundle automáticamente para evitar correr los tests sobre un
bundle obsoleto.

> `tests/e2e/dashboard.spec.ts` incluye un test de regresión **visual**:
> comprueba que el root del dashboard computa a `display: grid`, que el
> `<main>` tiene un ancho legible (> 400 px) y que las tablas pineadas
> llevan el CSS de bloques aplicado (`border-collapse: collapse`, padding
> de celda ≠ 1 px). Estos bugs no los caza la suite funcional — sólo se
> ven renderizados.

> Si en el futuro Playwright entra a CI, cachear `~/.cache/ms-playwright`
> (chromium) entre runs — sin cache añade ~1 min al primer run.

### Atado de release a tag git

El workflow debe correr los 4 pasos en cada push a `main` y en cada PR. Para releases (push de tag `vX.Y.Z`), añadir un job extra que:

1. Verifica que el tag apunta a un commit con CI verde.
2. Crea release notes a partir de la sección correspondiente del `CHANGELOG.md`.
3. (Opcional) Notifica a Satis/Packeton/Private Packagist si la empresa los usa con webhook.

Composer ya entiende los tags git directamente — un host puede hacer `composer require rnkr69/lara-chatbot:^0.4` en cuanto el tag esté pushed, sin esperar al servidor de paquetes.

---

## FAQ

**¿Por qué incluís un `.github/workflows/ci.yml` si el git host real puede no ser GitHub?**
Como referencia ejecutable. Las primeras dos integraciones piloto se harán
contra GitHub, así que el workflow funciona out-of-the-box ahí. Empresas
con otro git host (GitLab CI, Bitbucket, Gitea, Drone) traducen los pasos
del YAML — son los mismos cuatro: composer install + pest, npm install +
typecheck + vitest + build. La matriz y los comandos en esta guía son la
fuente de verdad portable; el YAML es la implementación de referencia
para GitHub.

**¿Cómo testeo localmente la receta `composer require` antes de publicar un tag?**
Usa `dev-main` mientras el branch está en desarrollo:
```json
"require": { "rnkr69/lara-chatbot": "dev-main" }
```
Composer aceptará el branch `main` con `minimum-stability: dev`. Para testear un tag candidato sin pushearlo a un repo público, una opción es `--repository '{"type":"path","url":"../chatbot"}'` apuntando a tu working copy local.

**¿Qué hago si el host está en Laravel 10?**
Por ahora, no soportado en v1 (decisión D1). Los hosts en L10 deben quedarse en una versión previa del chatbot (no existe — v1 es la primera) o subir a L11. Cuando todos los hosts piloto suban a L11+, esto deja de ser problema.
