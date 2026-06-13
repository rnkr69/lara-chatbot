# Telemetría de coste

*[English](telemetry.md) · Español*

`rnkr69/lara-chatbot` persiste `tokens_in` y `tokens_out` por cada assistant
message en la tabla `chatbot_messages` y emite el evento
`Rnkr69\LaraChatbot\Events\MessagePersisted` tras cada turn. El paquete **no
envía datos fuera del host por defecto** — sólo escribe en la BD del
host y dispara el evento. Lo que pase a partir de ahí lo decide el host.

Esta guía cubre dos caminos:

1. **Report ad-hoc por CLI**: `php artisan chatbot:cost-report` — útil
   para "cuánto cuesta esto al mes" sin instrumentar nada.
2. **Sink continuo vía evento**: el host engancha un listener al
   `MessagePersisted` para empujar a Prometheus / BigQuery / OTel / lo
   que use.

---

## 1. Report ad-hoc

```bash
php artisan chatbot:cost-report --since=2026-05-01
```

Salida (modo `table`, default):

```
Cost report: 2026-05-01 → 2026-05-16
+---------+-----------+--------------------+-----------+------------+------------+-------------+
| user_id | provider  | model              | tokens_in | tokens_out | cost_in_$  | cost_out_$  |
+---------+-----------+--------------------+-----------+------------+------------+-------------+
| 1       | anthropic | claude-sonnet-4-6  | 12,300    | 4,500      | $0.0369    | $0.0675     |
| 2       | anthropic | claude-sonnet-4-6  | 8,000     | 2,100      | $0.0240    | $0.0315     |
| 7       | openai    | gpt-4o-mini        | 3,200     | 900        | $0.0005    | $0.0005     |
+---------+-----------+--------------------+-----------+------------+------------+-------------+
Totals: tokens_in=23,500 tokens_out=7,500 cost_in=$0.0614 cost_out=$0.0995
```

Opciones:

| Flag | Default | Descripción |
|---|---|---|
| `--since=YYYY-MM-DD` | primer día del mes | Fecha inicial (inclusiva). |
| `--until=YYYY-MM-DD` | ahora | Fecha final (exclusiva). |
| `--user=ID` | todos | Filtra por `user_id` concreto. |
| `--format=table\|json\|csv` | `table` | Formato de salida. |

`--format=json` produce el dataset machine-readable con `since`, `until`,
`rows[]` (con `user_id`, `provider`, `model`, `tokens_in`, `tokens_out`,
`cost_input`, `cost_output`) y `totals`. `--format=csv` es la misma cosa
sin envoltorio, una fila por bucket `(user_id, provider, model)`.

### Tarifas

Las tarifas viven en `config/chatbot.php → telemetry.prices`:

```php
'telemetry' => [
    'prices' => [
        'anthropic' => [
            'claude-opus-4-7'   => ['input' => 15.00, 'output' => 75.00],
            'claude-sonnet-4-6' => ['input' =>  3.00, 'output' => 15.00],
            'claude-haiku-4-5'  => ['input' =>  1.00, 'output' =>  5.00],
        ],
        'openai' => [
            'gpt-4o'      => ['input' => 2.50, 'output' => 10.00],
            'gpt-4o-mini' => ['input' => 0.15, 'output' =>  0.60],
        ],
    ],
],
```

USD por **1M tokens**. Defaults indicativos — verifica contra la página
de pricing del proveedor antes de presentar un report al CFO; los precios
cambian sin aviso.

Si un par `(provider, model)` no tiene entrada, su columna de coste sale
como `n/a` (los tokens se reportan igual). Para Ollama / modelos
self-hosted, esto es lo esperable; déjalos sin tarifa.

### Provider/model efectivo

El report determina `provider` y `model` por **conversación**:

1. Si la conversación tiene `metadata.provider` / `metadata.model` (el
   host lo puede setear al crear la conversación), eso gana.
2. Si no, cae a los defaults globales (`chatbot.provider` /
   `chatbot.model`).

Esto significa que si un host hace override por conversación (p.ej. usar
un modelo más barato para chitchat y uno mejor para tools complejas),
el report distingue las dos rutas en filas separadas.

---

## 2. Sink continuo vía evento

`MessagePersisted` se dispara una vez por turn, después de que el
assistant message llegue al disco y antes del `done` SSE. Shape:

```php
final class MessagePersisted
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {}
}
```

El host engancha un listener en su `EventServiceProvider`:

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \Rnkr69\LaraChatbot\Events\MessagePersisted::class => [
        \App\Listeners\Telemetry\PushTokensToPrometheus::class,
    ],
];
```

### Recipe 1 — Counter Prometheus

```php
// app/Listeners/Telemetry/PushTokensToPrometheus.php
use Rnkr69\LaraChatbot\Events\MessagePersisted;
use Prometheus\CollectorRegistry;

class PushTokensToPrometheus
{
    public function __construct(private CollectorRegistry $prom) {}

    public function handle(MessagePersisted $e): void
    {
        $labels = [
            'provider' => $e->provider ?? 'unknown',
            'model'    => $e->model ?? 'unknown',
            'user_id'  => (string) $e->user->getAuthIdentifier(),
        ];
        $this->prom->getOrRegisterCounter('chatbot', 'tokens_in_total',
            'Prompt tokens billed by upstream LLM', array_keys($labels))
            ->incBy($e->tokensIn, array_values($labels));
        $this->prom->getOrRegisterCounter('chatbot', 'tokens_out_total',
            'Completion tokens billed by upstream LLM', array_keys($labels))
            ->incBy($e->tokensOut, array_values($labels));
    }
}
```

### Recipe 2 — Stream a BigQuery (queued)

```php
// app/Listeners/Telemetry/StreamToBigQuery.php
use Illuminate\Contracts\Queue\ShouldQueue;
use Rnkr69\LaraChatbot\Events\MessagePersisted;

class StreamToBigQuery implements ShouldQueue
{
    public function handle(MessagePersisted $e): void
    {
        \App\Services\BigQuery::table('chatbot_turns')->insert([
            'timestamp'   => $e->message->created_at?->toIso8601String(),
            'user_id'     => $e->user->getAuthIdentifier(),
            'conv_id'     => $e->conversation->id,
            'provider'    => $e->provider,
            'model'       => $e->model,
            'tokens_in'   => $e->tokensIn,
            'tokens_out'  => $e->tokensOut,
        ]);
    }
}
```

Cualquier sink lento (OTel, HTTP a un service externo, BigQuery) **debe
implementar `ShouldQueue`** — el evento se dispara en el camino crítico
del `ChatService::handle()`, justo antes de que el frame `done` salga
hacia el navegador. Un listener síncrono lento añade esa latencia al
TTL del usuario.

---

## ¿Por qué el paquete no envía datos fuera por defecto?

Por la misma razón que la cascada de autorización: los datos del host
son del host. El paquete persiste lo mínimo para que el report local
funcione (`tokens_in`/`tokens_out` por message) y emite un evento bien
shaped para que el host decida si exporta — y a dónde.

Si en el futuro la empresa quiere telemetría centralizada para todos los
hosts del paquete, el patrón es publicar un listener en un paquete
satélite (`rnkr69/lara-chatbot-telemetry-bigquery`, `rnkr69/lara-chatbot-telemetry-otel`)
que el host instale por separado.
