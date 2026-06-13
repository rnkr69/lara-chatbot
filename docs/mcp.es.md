# Bridge MCP — `mcp.<server>.<tool>`

*[English](mcp.md) · Español*

> Documento inicial. Una versión más extensa con flujos completos está disponible en la documentación del paquete.

El paquete puede exponer al LLM tools provistas por servidores **MCP**
(Model Context Protocol) externos, sin que el host tenga que escribir un
adapter por cada uno. La integración se apoya en
[`prism-php/relay`](https://github.com/prism-php/relay), que se instala en el
host como dependencia opcional.

## Cuándo usarlo

- Tienes un servidor MCP existente (un servicio interno, una integración con
  otro producto, un wrapper de un SaaS) y quieres que el LLM lo invoque
  como una tool más.
- No quieres que el chatbot dependa físicamente de la implementación del
  server: si el server cambia su lista de tools, el chatbot la refresca por
  cache TTL sin redeploy.

Si lo que necesitas es escribir lógica concreta de tu host (consultar
modelos Eloquent, llamar a APIs internas), usa `BackendTool` directo (ver
[`backend-tools.es.md`](backend-tools.es.md)). MCP encaja cuando ya tienes el
server o cuando el server lo construye otro equipo.

## Instalación

`prism-php/relay` no se incluye en `composer.json` del paquete: es opt-in
del host.

```sh
composer require prism-php/relay
php artisan vendor:publish --tag=relay-config
```

Si declaras servers en `chatbot.mcp.servers` sin instalar Relay, el paquete
sigue funcionando: las tools MCP simplemente no se cargan y
`php artisan chatbot:tools:list` lo señala con un warning accionable.

## Configurar un server

La configuración vive en dos sitios:

1. **`config/relay.php`** — `prism-php/relay` lo publica. Aquí declaras el
   transporte (HTTP / STDIO), la URL o el comando, headers, timeouts, etc.

   ```php
   // config/relay.php
   'servers' => [
       'tickets' => [
           'transport' => Prism\Relay\Enums\Transport::Http,
           'url'       => 'https://mcp.example.com',
           'api_key'   => env('TICKETS_MCP_KEY'),
           'timeout'   => 30,
       ],
   ],
   ```

2. **`config/chatbot.php`** — el bridge sólo necesita metadata extra:

   ```php
   'mcp' => [
       'servers' => [
           'tickets' => [
               'enabled'     => true,
               'permissions' => ['tickets.use_mcp'],
               'cache_ttl'   => 300, // segundos; 0 desactiva
           ],
       ],
   ],
   ```

   Las **claves** del array deben coincidir con los nombres de server
   declarados en `config/relay.php`.

## Cómo aparecen al LLM

Cada tool del server se registra en el `ToolRegistry` con el prefijo
`mcp.<server>.<tool>`. Para el ejemplo anterior, si el server `tickets`
expone una tool MCP llamada `search_open`, el LLM la verá como
`mcp.tickets.search_open`.

`php artisan chatbot:tools:list` muestra el origen de cada tool:

```
+----------------------------------+--------------+--------------------+
| Name                             | Origin       | Permissions        |
+----------------------------------+--------------+--------------------+
| list_my_invoices                 | local        | invoices.read      |
| mcp.tickets.search_open          | mcp:tickets  | tickets.use_mcp    |
| mcp.tickets.create               | mcp:tickets  | tickets.use_mcp    |
+----------------------------------+--------------+--------------------+
```

## Autorización

- **Permisos** declarados en `chatbot.mcp.servers.<server>.permissions`
  aplican a **todas** las tools del server (AND con el `Authorizer`
  configurado en `chatbot.authorization.resolver`). Si quieres exponer
  algunas tools de un server pero no otras, separa el server en dos en
  `config/relay.php`.

- **Scope de datos** (`AccessScope`) **no aplica** a tools MCP: el server
  externo es la fuente de verdad y el chatbot no puede saber qué `user_id`
  filtrar. El adapter declara `defaultScope = All`. Si el server expone
  datos sensibles, configúralo del lado del server (auth headers, JWT,
  scopes específicos por usuario).

- **Tenant scope** **no aplica** a tools MCP: el adapter declara
  `tenantScope = false` y por tanto no exige `TenantResolver` en el
  contenedor. El server MCP debe aplicar su propia segregación si la
  necesita.

## Cache

`cache_ttl` controla cuánto tiempo el bridge cachea la lista de tools de
cada server, evitando un round-trip a Relay por cada turno del chat. La
cache vive en el cache store por defecto de Laravel.

- `cache_ttl: 0` o ausente → sin cache (Relay se consulta cada arranque).
- `cache_ttl: N` → la lista se refresca cada N segundos.

La cache se aplica al **listado** de tools, no a las invocaciones. Cada
ejecución de tool sí golpea al server.

## Tolerancia a fallos

- Si Relay no está instalado: el paquete funciona; sólo las tools MCP
  faltan. Warning en `chatbot:tools:list`.
- Si un server cae durante el boot: se loguea warning y el resto de
  servers se cargan normalmente. El paquete no aborta el boot.
- Si una invocación de tool falla en runtime: el adapter devuelve
  `ToolResult::error('runtime', <mensaje>)`. El LLM lo recibe como un
  resultado de tool con error y puede informar al usuario o reintentar.

## Limitaciones de v1

- No hay granularidad de permisos por tool dentro de un server (todas o
  ninguna).
- No hay step-up auth ni `confirmation: confirm` para tools MCP — todas
  ejecutan en `Auto`. Si necesitas confirmación, expón la operación como
  una `FrontendTool` local que decida cuándo invocar la MCP por debajo.
- No se exponen los `artifacts` de `ToolOutput` al widget (sólo el campo
  `result`). Queda en backlog si emerge la necesidad.
