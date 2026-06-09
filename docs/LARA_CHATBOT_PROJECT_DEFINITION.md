# Definición del proyecto: Paquete Laravel Chatbot

> Documento de visión y definición. Explica el **qué**, el **por qué** y el **cómo** del proyecto. Para el plan de ejecución detallado por épicas, ver `ROADMAP.md`.

---

## 1. Resumen ejecutivo

Construimos un paquete Laravel privado, distribuible vía Composer interno, que añade a cualquier proyecto de la empresa un asistente conversacional con LLM capaz no sólo de responder en lenguaje natural, sino también de **ejecutar acciones reales** tanto en el backend como en el frontend de la aplicación que lo aloja, respetando los permisos y los datos accesibles a cada usuario.

El paquete es **genérico**: define contratos, primitivas y un widget agnóstico, pero no impone qué tools concretas existen. Cada proyecto host añade las suyas. Es **multi-versión** (Laravel 11 y 12), **multi-stack** (apps Blade clásicas y SPAs como Inertia o Livewire), y **multi-proveedor** de LLM (Anthropic, OpenAI, Ollama, etc.) gracias a Prism.

---

## 2. Contexto y problema

### 2.1 Situación actual
La empresa mantiene varios proyectos Laravel, todos con un stack común (Laravel 11+, Spatie Permission para autorización, modelos con relaciones de propiedad y, en algunos casos, jerarquías manager→equipo). Hay una demanda creciente de incorporar asistentes conversacionales en estos productos para tareas como:

- Consultar datos del propio sistema en lenguaje natural ("muéstrame mis facturas vencidas").
- Disparar acciones operativas ("crea una tarea para el cliente X").
- Guiar al usuario por la UI ("llévame a la pantalla de configuración y abre el panel de notificaciones").
- Servir como capa de ayuda contextual y onboarding.

### 2.2 Problemas que queremos evitar
1. **Duplicación de esfuerzo.** Si cada proyecto implementa su propio chatbot, multiplicamos el coste y diluimos la calidad.
2. **Acoplamiento a un proveedor LLM.** Atarse a un único proveedor compromete coste, latencia y disponibilidad.
3. **Acoplamiento a un stack frontend.** Los proyectos usan combinaciones distintas (Blade, Livewire, Inertia+Vue, Inertia+React); una solución que sólo funcione en uno deja a los demás fuera.
4. **Filtraciones de datos.** Un asistente con acceso al backend puede, si no se diseña con cuidado, exponer información que el usuario no debería ver. La autorización tiene que ser una primera clase del diseño, no un parche.
5. **Asistentes "de juguete"** que sólo conversan. Sin la capacidad de actuar (en backend y en frontend), el valor para el usuario es marginal.

### 2.3 Alternativas consideradas y descartadas
- **Servicios SaaS de chatbot externos.** Descartados: difíciles de integrar con la autorización fina del host, datos de cliente saliendo de nuestro perímetro, dependencia del proveedor.
- **Una integración a medida por proyecto.** Descartada por duplicación.
- **Un paquete acoplado a Inertia o a Livewire.** Descartado: ningún stack cubre el 100% de proyectos actuales y futuros.
- **Cliente directo de un único LLM (p. ej. SDK de Anthropic).** Descartado: si en el futuro queremos cambiar de proveedor o usar varios, hay que reescribir cada integración.

---

## 3. Visión

> Un dev de cualquier proyecto de la empresa puede añadir, en menos de media hora, un asistente conversacional que entiende el dominio de su aplicación, respeta los permisos de cada usuario y puede tanto consultar datos como actuar sobre la interfaz, sin tener que conocer los detalles del LLM ni reimplementar nada que ya esté resuelto.

### 3.1 Objetivos de negocio
- **Tiempo a producción**: que la primera versión funcional en un proyecto nuevo se mida en horas, no en sprints.
- **Reutilización**: una sola base de código mantenida, n proyectos beneficiados.
- **Soberanía técnica**: capacidad de cambiar de proveedor LLM sin reescribir aplicaciones.
- **Seguridad por defecto**: ningún proyecto integrado debería poder filtrar datos por error de configuración del chatbot.

### 3.2 Objetivos técnicos
- Compatibilidad con Laravel 11 y 12 (y la siguiente mayor cuando salga, durante el solape).
- Funcionar igual de bien en apps Blade clásicas y en SPAs.
- Soportar múltiples proveedores LLM detrás de un único contrato.
- Permitir que cada proyecto extienda el bot con tools propias de back y de front sin tocar el paquete.
- Integrarse de forma **opcional** con servidores MCP externos para reutilizar tools que ya existan.

---

## 4. Alcance

### 4.1 Dentro de alcance (v1)
- Conversación con LLM con streaming en tiempo real.
- Persistencia de conversaciones e historial.
- Sistema de **Backend Tools** con autorización integrada.
- Sistema de **Frontend Tools** (navegación, manipulación de DOM, formularios, modales, toasts, render de bloques tipados en el chat).
- Modelo de autorización en tres dimensiones (permiso, scope de datos, ownership puntual) con autodiscovery de Spatie Permission.
- Widget como Web Component agnóstico de framework.
- Página dedicada de chat compartiendo estado con el widget.
- Detección automática SPA/MPA.
- Bridge opcional a servidores MCP vía `prism-php/relay`.
- Niveles de confirmación de acciones (`auto`, `confirm`, `manual`) para tools de frontend.
- Comando de instalación `php artisan chatbot:install`.

### 4.2 Fuera de alcance (v1)
- Multimodal (imágenes, audio, vídeo en mensajes).
- RAG sobre datos del host con embeddings (planeado para v1.1).
- Backend tools con flujo `confirm`/`manual` con pausa y reanudación de la conversación (planeado para v2).
- Telemetría detallada de costes por usuario (v1.1).
- Pasarelas de voz / TTS / STT.
- Soporte para WebSockets (Reverb): el streaming va por SSE, suficiente para el caso de uso.

### 4.3 No-objetivos explícitos
- **No** aspiramos a competir con productos como ChatGPT Enterprise. Esto es infraestructura interna, no un producto SaaS.
- **No** pretendemos abstraer todo Laravel: el paquete asume que el host es un Laravel idiomático.
- **No** vamos a soportar versiones de Laravel anteriores a la 11.

---

## 5. Usuarios y stakeholders

### 5.1 Usuarios finales
Los usuarios de los proyectos host. Interactúan con el chatbot vía el widget o la página dedicada. **Lo que esperan:** respuestas útiles, rápidas, que respeten lo que pueden ver y hacer en el sistema, y que el bot pueda llevarles a la pantalla correcta o rellenar lo que necesitan sin fricción.

### 5.2 Devs de proyecto host
Integran el paquete y crean sus tools. **Lo que esperan:** documentación clara, contratos sin ambigüedad, ejemplos copiables, y que el sistema falle de forma ruidosa cuando algo está mal configurado, no de forma silenciosa.

### 5.3 Equipo del paquete (mantenimiento)
Mantiene el paquete en el git privado. **Lo que esperan:** suite de tests amplia para hacer upgrades de Prism o Laravel sin miedo, y un CHANGELOG honesto.

### 5.4 Equipos de seguridad / compliance
Necesitan auditar qué hace el bot, qué datos toca, qué tools se ejecutan. **Lo que esperan:** logging exhaustivo de invocaciones de tools, permisos verificados de forma trazable, y la capacidad de desactivar o limitar el bot por usuario o rol.

---

## 6. Casos de uso representativos

Estos casos no agotan lo posible; sirven para ilustrar la forma del producto.

1. **Consulta con scope.** Un empleado de soporte pregunta "¿cuántos tickets abiertos tengo?". El bot llama a `list_tickets` con `scope=self`, devuelve sólo los del usuario, responde con un bloque tabla y un resumen.
2. **Consulta como manager.** El mismo "list_tickets" pero el usuario es manager: se aplica `scope=team` y se devuelven los tickets de su equipo. La misma tool, distinto resultado, sin que la tool tenga que conocer la jerarquía: se la resuelve el `ScopeResolver` del host.
3. **Acción guiada en frontend.** Un usuario dice "rellena el formulario de nueva factura para el cliente Acme". El bot, ya en la pantalla de creación, llama a `fill_form` con los campos, marca el de "cliente" como Acme, y el formulario aparece relleno listo para revisar. Como `submit=true` requeriría confirmación, el bot se queda corto y pide al usuario que confirme.
4. **Navegación.** "Llévame a configuración de notificaciones." El bot llama a `navigate` con la ruta nombrada del host. Si la app es SPA, transición sin recarga; si es MPA, navegación normal.
5. **Bloque rico en chat.** "Muéstrame el detalle del cliente 123." El bot llama a `get_customer`, recibe los datos, y emite un bloque `card` con campos clave y dos botones de acción. El host ha registrado un renderer custom para `card` con su estilo de marca.
6. **Permiso denegado, manejado con elegancia.** Un usuario sin el permiso `invoices.read` pregunta por facturas. La tool no se le ofrece al LLM porque el filtrado por permisos lo excluye antes; el bot responde algo como "no tengo acceso a esa información para tu rol".
7. **Tool MCP externa.** El proyecto tiene conectado un MCP server de "monitorización" propio. El bot puede usar `mcp.monitoring.get_alerts` igual que cualquier tool nativa.

---

## 7. Principios de diseño

Estos principios guían las decisiones cuando el roadmap no las cubre explícitamente.

### 7.1 Genérico por contrato, no por configuración
El paquete no contiene lógica de dominio. Cada proyecto la aporta vía contratos. Si nos sentimos tentados de añadir una tool específica para un proyecto al core, es señal de que el contrato no es suficientemente expresivo y hay que arreglarlo ahí.

### 7.2 Seguro por defecto
Una tool sin declaración explícita de permisos no se carga. Un scope `team` o `all` sin `ScopeResolver` implementado lanza al boot, no en runtime. Una respuesta del LLM nunca incluye datos que el usuario no pueda ver, porque el filtrado ocurre antes de que la tool devuelva su resultado.

### 7.3 Agnóstico de stack
Web Component vanilla, sin React ni Vue como dependencia. Convenciones HTML (`data-chatbot-*`) en lugar de bindings de framework. Si un día queremos un wrapper específico para un stack concreto, irá en un paquete satélite, no aquí.

### 7.4 El LLM es intercambiable
Toda interacción con el modelo pasa por Prism. Si Anthropic sube precios o cae, cambiar a OpenAI o a un Ollama local es un cambio de configuración, no un refactor.

### 7.5 Falla ruidosamente
Configuración incorrecta debe dar errores claros al boot o al primer uso, no fallos silenciosos en producción. Logs estructurados de cada invocación de tool con su resultado y el usuario implicado.

### 7.6 Pequeño en runtime
El widget compilado por debajo de 80 KB gzip. El paquete PHP sin dependencias innecesarias. Los proyectos host ya tienen suficiente peso.

### 7.7 Conservador en cambios
SemVer estricto. Breaking changes acumulados para mayors. Compatibilidad con las dos últimas mayors de Laravel.

---

## 8. Cómo: enfoque arquitectónico

> Detalle técnico en `ROADMAP.md`. Aquí, sólo las grandes líneas.

### 8.1 Tres capas de tools
- **Backend Tools (PHP)**: clases del host que leen o escriben en la base de datos, llaman a APIs internas, etc. Pasan por la capa de autorización del paquete antes de ejecutarse.
- **Frontend Tools (PHP shim + JS handler)**: la parte PHP valida y autoriza; el efecto real ocurre en el navegador del usuario, ejecutado por el widget.
- **Tools MCP**: tools externas expuestas por servidores MCP, integradas como Backend Tools "remotas" pero igualmente sometidas a la capa de autorización local.

### 8.2 Autorización en tres dimensiones
- **Permiso**: ¿puede invocar esta tool? (Spatie / Gate / Custom)
- **Scope de datos**: ¿qué subconjunto de registros? (`self` / `team` / `all`)
- **Ownership puntual**: ¿este registro concreto? (Policy del host)

Las tres se aplican en cascada antes de ejecutar cualquier tool. Detalle en `ROADMAP.md` §2.

### 8.3 Streaming SSE
La elección de SSE sobre WebSockets es deliberada. SSE va sobre HTTP estándar, sin infraestructura extra (sin Reverb, sin Redis pub/sub obligatorio), funciona detrás de cualquier proxy razonable y es suficientemente expresivo para texto incremental, eventos de tool y acciones de frontend. Si algún día la bidireccionalidad fuera crítica (p. ej. colaboración multi-usuario en un mismo chat), se reabre la decisión.

### 8.4 Web Component como puente neutro
El widget vive en el shadow DOM. No se contamina con CSS del host ni lo contamina. Expone una API `window.Chatbot` y eventos DOM custom para integrarse con cualquier framework. Para casos avanzados, el host registra renderers de bloques custom sin tocar el código del widget.

### 8.5 Page Context declarativo
El bot conoce qué pantalla está viendo el usuario porque la página lo declara, vía meta tag o vía API JS. No leemos el DOM completo (caro, frágil, riesgo de fugas). El host decide qué exponer.

### 8.6 Convenciones HTML para acción
En lugar de un DSL específico, usamos atributos `data-chatbot-*` para marcar formularios, campos y elementos accionables. Es código HTML normal del host, sin librería extra. La frontend tool `fill_form` opera sobre estos marcadores.

---

## 9. Decisiones clave y sus trade-offs

| Decisión | A favor | En contra |
|---|---|---|
| Prism como capa LLM | Multi-provider gratis, comunidad activa, abstracciones idiomáticas Laravel | Dependencia de un paquete relativamente joven; estamos atados a su ritmo de soporte de nuevas APIs de proveedores |
| Web Component vanilla | Cero acoplamiento de framework; un solo bundle para todo | Sin reactividad gratis; algunas integraciones (p. ej. Inertia) requieren adaptadores adicionales |
| SSE en lugar de WebSockets | Sin infra extra; simple; suficiente | Unidireccional; reconexión manual; algunos hostings agresivos pueden cortar conexiones largas |
| Tools híbridas PHP + MCP | Lo mejor de ambos mundos: DX excelente para tools del proyecto, interoperabilidad con tools externas | Dos rutas de creación de tools que mantener documentadas y testeadas |
| Autorización con autodiscovery de Spatie | Funciona con o sin Spatie sin acoplar el paquete | Algo de "magia" implícita; hay que documentarlo bien |
| `ScopeResolver` implementado por el host | Independencia total del esquema de teams | Cada proyecto tiene que escribirlo; sin él, los scopes `team` y `all` no se pueden usar |
| Backend tools con sólo confirmación `auto` en v1 | Reduce dramáticamente la complejidad inicial | Limitación funcional que algunos proyectos pedirán pronto |
| Convenciones `data-chatbot-*` en el HTML | Funciona en cualquier stack sin librerías | Requiere disciplina del host para marcar correctamente sus elementos |

---

## 10. Riesgos

### 10.1 Técnicos
- **Cambios disruptivos en Prism.** Mitigación: tests con `Prism::fake` exhaustivos y pin de versión con review de upgrade.
- **Hallucination de tool calls.** El LLM puede inventarse parámetros o llamar tools inadecuadas. Mitigación: validación estricta de args (JSON Schema), descripciones cuidadas, y observabilidad para detectar patrones malos.
- **Latencia con muchas tools.** Una sola llamada puede llevar metadatos de decenas de tools al LLM. Mitigación: el `ToolRegistry::forUser` filtra por permisos, reduciendo el catálogo enviado.
- **SSE detrás de proxies.** Algunos hostings cierran conexiones largas. Mitigación: keep-alive heartbeat, reconexión cliente, documentar configuración en `deployment.md`.
- **Tamaño del bundle JS.** Riesgo de crecimiento. Mitigación: presupuesto explícito (80 KB gzip) verificado en CI.

### 10.2 De producto
- **Permiso de actuar mal calibrado.** Una tool con `auto` que debería ser `confirm` puede causar acciones indeseadas. Mitigación: defaults conservadores y revisión obligatoria del `confirmation` en code review.
- **El LLM "lo hace todo mal".** Si las descripciones de tools son ambiguas, el bot las usa mal. Mitigación: guía de buenas prácticas para describir tools en la doc del host.

### 10.3 De seguridad
- **Filtración de datos por scope mal configurado.** El riesgo principal del paquete. Mitigación: scope `self` por defecto; el host tiene que pedir explícitamente `team` o `all`; auditoría de logs de tools.
- **Inyección via page_context.** El host podría enviar contenido del usuario en el context. Mitigación: sanitización backend, límite de tamaño, guía explícita de qué meter y qué no.
- **Inyección de prompt vía datos de la BD.** Si una tool devuelve datos que contienen instrucciones, el LLM puede dejarse manipular. Mitigación: separación visual y semántica de "data" vs "instructions" en el system prompt; documentación.

### 10.4 De adopción
- **Curva de aprendizaje al crear tools.** Mitigación: stubs vía artisan, ejemplos copiables, documentación con casos de uso reales.
- **Resistencia a integrar por miedo a costes LLM.** Mitigación: telemetría desde v1.1; rate limit configurable desde v1.

---

## 11. Métricas de éxito

### 11.1 Cuantitativas
- **Tiempo de integración inicial** en un proyecto nuevo: < 30 minutos hasta primer mensaje funcional.
- **Cobertura de tests** del núcleo (`Authorization`, `Services`, `Tools`): ≥ 75%.
- **Bundle del widget**: ≤ 80 KB gzip.
- **Proyectos host integrados**: al menos 2 en los primeros 3 meses tras v1, al menos 5 en 12 meses.
- **Incidentes de seguridad por filtración de datos vía chatbot**: 0.
- **Tiempo medio de respuesta del primer token (TTFT)** en condiciones normales: < 2 s.

### 11.2 Cualitativas
- Devs de proyectos host capaces de añadir una tool nueva siguiendo sólo la doc, sin abrir tickets al equipo del paquete.
- Equipo de seguridad satisfecho con la trazabilidad y el modelo de autorización.
- Capacidad demostrada de cambiar de proveedor LLM en un proyecto en menos de un día.

---

## 12. Roadmap de alto nivel

El detalle por épicas vive en `ROADMAP.md`. A nivel macro:

- **Fase 0 — Fundamentos.** Bootstrap, configuración, persistencia, autorización.
- **Fase 1 — Capa de chat.** Prism, backend tools, MCP, ChatService, endpoint SSE.
- **Fase 2 — Frontend.** Widget, frontend tools, page context, bloques tipados, confirmación.
- **Fase 3 — Distribución.** Página dedicada, comando de instalación, publicación en composer privado.
- **Fase 4 — Calidad.** Testing en matriz, documentación host.

Tras v1, líneas naturales de evolución:
- v1.1: telemetría de uso, RAG opcional sobre datos del host, tools MCP destacadas en UI.
- v1.2: multimodal (al menos imágenes en mensajes).
- v2: backend tools con confirmación interactiva, multi-conversación lado a lado, colaboración (varios usuarios en una conversación con permisos finos).

---

## 13. Glosario

- **Backend Tool**: clase PHP del host que el LLM puede invocar para leer o escribir datos del sistema.
- **Frontend Tool**: tool cuyo efecto ocurre en el navegador (navegación, manipulación de DOM, etc.). Tiene un shim en backend para validación y autorización.
- **MCP (Model Context Protocol)**: protocolo abierto para que LLMs interactúen con tools y datos externos. Aquí se integra como fuente opcional de tools.
- **Prism**: paquete PHP que ofrece un interfaz unificado a múltiples proveedores LLM.
- **Relay**: paquete oficial de Prism que actúa como cliente MCP.
- **Scope (`self`/`team`/`all`)**: alcance de datos accesibles para una invocación de tool.
- **ScopeResolver**: contrato implementado por el host que mapea un scope a una lista concreta de IDs de usuario.
- **Page Context**: información estructurada que la página actual del host expone al chatbot para darle contexto.
- **Bloque tipado**: elemento de UI estructurado (card, table, chart…) que el LLM puede emitir y el widget renderiza.
- **Web Component**: estándar W3C para componentes HTML reutilizables encapsulados en shadow DOM.
- **SSE (Server-Sent Events)**: protocolo HTTP unidireccional servidor→cliente para streaming de eventos.

---

**Fin del documento.** Este texto es la referencia de "por qué hacemos lo que hacemos". El cómo concreto se elabora en `ROADMAP.md`.
