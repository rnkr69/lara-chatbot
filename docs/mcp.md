# Bridge MCP — `mcp.<server>.<tool>`

*English · [Español](mcp.es.md)*

> Initial document. A more complete version with end-to-end flows is available in the package documentation.

The package can expose tools provided by external **MCP**
(Model Context Protocol) servers to the LLM, without the host having to write
an adapter for each one. The integration relies on
[`prism-php/relay`](https://github.com/prism-php/relay), which is installed in
the host as an optional dependency.

## When to use it

- You have an existing MCP server (an internal service, an integration with
  another product, a SaaS wrapper) and you want the LLM to invoke it as
  just another tool.
- You don't want the chatbot to physically depend on the server's
  implementation: if the server changes its list of tools, the chatbot
  refreshes it via cache TTL without a redeploy.

If you need to write concrete host logic (querying Eloquent models, calling
internal APIs), use `BackendTool` directly (see
[`backend-tools.md`](backend-tools.md)). MCP fits when you already have the
server or when the server is built by another team.

## Installation

`prism-php/relay` is not included in the package's `composer.json`: it is
opt-in for the host.

```sh
composer require prism-php/relay
php artisan vendor:publish --tag=relay-config
```

If you declare servers in `chatbot.mcp.servers` without installing Relay, the
package keeps working: MCP tools simply won't load and
`php artisan chatbot:tools:list` flags this with an actionable warning.

## Configuring a server

Configuration lives in two places:

1. **`config/relay.php`** — published by `prism-php/relay`. Here you declare
   the transport (HTTP / STDIO), the URL or command, headers, timeouts, etc.

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

2. **`config/chatbot.php`** — the bridge only needs extra metadata:

   ```php
   'mcp' => [
       'servers' => [
           'tickets' => [
               'enabled'     => true,
               'permissions' => ['tickets.use_mcp'],
               'cache_ttl'   => 300, // seconds; 0 disables
           ],
       ],
   ],
   ```

   The **array keys** must match the server names declared in
   `config/relay.php`.

## How they appear to the LLM

Each server tool is registered in the `ToolRegistry` with the prefix
`mcp.<server>.<tool>`. For the example above, if the `tickets` server exposes
an MCP tool named `search_open`, the LLM will see it as
`mcp.tickets.search_open`.

`php artisan chatbot:tools:list` shows the origin of each tool:

```
+----------------------------------+--------------+--------------------+
| Name                             | Origin       | Permissions        |
+----------------------------------+--------------+--------------------+
| list_my_invoices                 | local        | invoices.read      |
| mcp.tickets.search_open          | mcp:tickets  | tickets.use_mcp    |
| mcp.tickets.create               | mcp:tickets  | tickets.use_mcp    |
+----------------------------------+--------------+--------------------+
```

## Authorization

- **Permissions** declared in `chatbot.mcp.servers.<server>.permissions`
  apply to **all** tools in the server (AND-ed with the `Authorizer`
  configured in `chatbot.authorization.resolver`). If you want to expose
  some tools from a server but not others, split the server into two in
  `config/relay.php`.

- **Data scope** (`AccessScope`) **does not apply** to MCP tools: the
  external server is the source of truth and the chatbot cannot know which
  `user_id` to filter on. The adapter declares `defaultScope = All`. If the
  server exposes sensitive data, configure that on the server side (auth
  headers, JWT, user-specific scopes).

- **Tenant scope** **does not apply** to MCP tools: the adapter declares
  `tenantScope = false` and therefore does not require a `TenantResolver` in
  the container. The MCP server must apply its own segregation if needed.

## Cache

`cache_ttl` controls how long the bridge caches the tool list of each server,
avoiding a round-trip to Relay on every chat turn. The cache lives in
Laravel's default cache store.

- `cache_ttl: 0` or absent → no cache (Relay is queried on every boot).
- `cache_ttl: N` → the list is refreshed every N seconds.

The cache applies to the **tool listing**, not to invocations. Each tool
execution does hit the server.

## Fault tolerance

- If Relay is not installed: the package works; only MCP tools are missing.
  Warning in `chatbot:tools:list`.
- If a server goes down during boot: a warning is logged and the remaining
  servers load normally. The package does not abort the boot.
- If a tool invocation fails at runtime: the adapter returns
  `ToolResult::error('runtime', <message>)`. The LLM receives it as a tool
  result with an error and can inform the user or retry.

## v1 Limitations

- There is no per-tool permission granularity within a server (all or
  nothing).
- There is no step-up auth or `confirmation: confirm` for MCP tools — all
  run in `Auto`. If you need confirmation, expose the operation as a local
  `FrontendTool` that decides when to invoke the MCP underneath.
- The `artifacts` of `ToolOutput` are not exposed to the widget (only the
  `result` field). This remains in the backlog if the need arises.
