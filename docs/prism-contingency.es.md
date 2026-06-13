# Plan B: ¿qué pasa si Prism muere?

*[English](prism-contingency.md) · Español*

`rnkr69/lara-chatbot` usa [`prism-php/prism`](https://github.com/prism-php/prism)
como capa de abstracción del LLM. Esto es deliberado y conveniente — un
host puede cambiar de Anthropic a OpenAI a Ollama editando una línea de
config — pero introduce una dependencia load-bearing en un paquete
todavía pre-`1.0`.

Este documento responde a la pregunta "¿qué hacemos si Prism deja de
mantenerse o sube a una v1 con breaking change que rompe lo nuestro?".

---

## Por qué dependemos de Prism

- **Multi-provider gratis.** Anthropic, OpenAI, Groq, Gemini, Mistral,
  Ollama detrás de la misma API. Construir esto en casa son meses.
- **Streaming con shape unificado.** Eventos `TextDeltaEvent`,
  `ToolCallEvent`, `ToolResultEvent`, `StreamEndEvent` con la misma
  semántica para todos los proveedores.
- **Tool calling con normalizing de schemas.** Los proveedores difieren
  en cómo expresan tools y args; Prism lo unifica.
- **Idiomático Laravel.** `Prism::fake()`, facade, container bindings —
  los tests del paquete (especialmente
  `tests/Feature/Llm/LlmGatewayTest.php` y
  `tests/Feature/Services/ChatServiceTest.php`) se apoyan en esto.

---

## Estado de Prism (a fecha de este doc — 2026-05-16)

- Versión instalada: `^0.100`. Pre-`1.0`. Mismo color de bandera que
  nosotros.
- Mantainership: comunidad activa, releases regulares (semanal en
  general). Sin entidad corporativa detrás visible.
- Riesgo bajo a corto plazo. Riesgo medio a 12-24 meses (la mayoría de
  paquetes Laravel pre-1.0 sobreviven, pero algunos no — y ya tuvimos
  que esquivar uno [`consoletvs/charts`] que sí murió).

Acción recomendada: el mantenedor del paquete revisa la actividad de
Prism cada release tag del paquete (mensual aproximadamente). Si hay
≥3 meses sin merges ni respuesta a issues, activar el plan B.

---

## Plan B: cliente directo

### Qué tendría que pasar

Reemplazar Prism con el SDK oficial del proveedor activo de cada host
(la mayoría hoy: SDK de Anthropic). Esto significa:

1. Reescribir `Rnkr69\LaraChatbot\Llm\LlmGateway` para hablar directo con
   el SDK del proveedor (HTTP a través del cliente oficial).
2. Mantener el shape de eventos que `ChatService` consume hoy
   (`TextDeltaEvent`, `ToolCallEvent`, `ToolResultEvent`, `StreamEndEvent`)
   construyéndolos a mano desde el stream del proveedor.
3. Reemplazar `Prism::fake()` en los ~10 tests que lo usan con un
   mock equivalente del cliente HTTP del SDK (Mockery, o el helper de
   testing del SDK si existe).
4. Perder el multi-provider gratis. Soportar un segundo proveedor
   requiere repetir los pasos 1-3 contra su SDK.

### Esfuerzo estimado

- Reescritura del gateway contra Anthropic SDK: **3-5 días-persona**.
  La abstracción ya existe (`LlmGateway`), sólo cambia su implementación.
- Tests adaptados: **1-2 días-persona**.
- Cobertura de los 6 proveedores actuales: **3-4 semanas-persona**
  (típicamente no haría falta — el host produce un PR sólo para los
  proveedores que realmente usa).

### Qué perderíamos

- **Multi-provider gratis.** Cambiar Anthropic→OpenaI deja de ser una
  línea de config y pasa a ser una PR.
- **MCP bridge** (que vive sobre `prism-php/relay`) — habría que
  reimplementarlo desde el spec de MCP o desinstalarlo. Como hoy
  ningún host integrado usa MCP (es experimental), esto se puede
  diferir.
- **Normalizing de schemas de tools.** Lo tendríamos que mantener
  nosotros si un día queremos soportar más de un proveedor.

---

## Trigger de activación

Activar el plan B si **uno o más** de los siguientes:

1. Prism queda **≥3 meses sin merges** ni respuesta a issues abiertos.
2. Prism sube a **v1.x con breaking change** que rompe nuestro `LlmGateway`
   y el upgrade requiere ≥2 semanas de trabajo (en ese punto, evaluar
   si vale más reescribir contra el SDK directo).
3. El proveedor que la mayoría de hosts usa (Anthropic hoy) saca una
   capability nueva que Prism tarda **≥1 mes** en soportar y bloquea
   algo que ya pidió un host.
4. Un análisis de seguridad detecta una **vulnerabilidad en Prism** sin
   parche disponible y necesitamos quitar la dependencia inmediatamente.

Hasta entonces: seguir en Prism, mantener tests amplios (`Prism::fake()`
cubre la superficie de uso), y revisar el estado al cortar cada tag.

---

## Lo que SÍ está hecho hoy

- `LlmGateway` es un contrato propio del paquete — el resto del código
  no importa nada de Prism directamente, sólo el gateway. Eso significa
  que la sustitución es local a un archivo.
- Tests del orquestador (`ChatServiceTest`, `EvalRunnerTest`) usan
  `Prism::fake()` — el día que reemplacemos Prism, esos mocks tienen
  que cambiar pero la cobertura de comportamiento del orquestador
  sigue siendo válida (el contract no cambia).
- Versión de Prism pinneada a `^0.100` en `composer.json` para evitar
  un upgrade automático a una potencial v1 disruptiva. Cuando Prism
  saque v1 estable, evaluar el upgrade antes de subir.

---

## TL;DR

El plan B existe, está documentado, es ejecutable en una semana, y la
arquitectura del paquete lo soporta sin reescritura masiva. No necesitas
ejecutarlo — sólo demostrarle al tech lead que existe.
