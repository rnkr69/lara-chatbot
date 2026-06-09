<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Rnkr69\LaraChatbot\Http\Requests\IndexConversationsRequest;
use Rnkr69\LaraChatbot\Http\Requests\StoreConversationRequest;
use Rnkr69\LaraChatbot\Http\Resources\ConversationResource;
use Rnkr69\LaraChatbot\Http\Resources\MessageResource;
use Rnkr69\LaraChatbot\Models\Conversation;

/**
 * CRUD básico de conversaciones (E10 ROADMAP §5/E10).
 *
 *   - `index`:   listado paginado (offset) con búsqueda por `title` LIKE `%q%`,
 *                ordenado por `updated_at desc` (recientes primero).
 *   - `store`:   crea una conversación asociada al usuario autenticado vía los
 *                campos morph (`user_type`+`user_id`), devuelve 201.
 *   - `show`:    devuelve la conversación + mensajes cursor-paginados por id
 *                descendente (último primero, como pide el ROADMAP).
 *   - `destroy`: soft delete (la migración crea `deleted_at`, el modelo usa
 *                `SoftDeletes`); responde 204 No Content.
 *
 * Política de policy / privacidad: cualquier acceso a una conversación ajena
 * devuelve 404 (no 403) — `Conversation::query()->forUser($user)->findOrFail($id)`.
 * Justificación: 403 filtra existencia ("la conversación existe pero no es
 * tuya"), mientras que 404 mantiene la conversación ajena indistinguible de
 * una inexistente. Coherente con la doctrina de Laravel para policies.
 */
class ConversationController extends Controller
{
    public function index(IndexConversationsRequest $request): JsonResponse
    {
        $user = $request->user();

        $perPage = $this->resolvePerPage(
            $request->input('per_page'),
            (int) config('chatbot.limits.conversations_per_page.default', 20),
            (int) config('chatbot.limits.conversations_per_page.max', 100),
        );

        $q = $request->input('q');

        $paginator = Conversation::query()
            ->forUser($user)
            ->when(is_string($q) && $q !== '', function ($query) use ($q) {
                $query->where('title', 'like', '%' . $q . '%');
            })
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return ConversationResource::collection($paginator)
            ->response();
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::create([
            'user_type' => $this->morphClassFor($user),
            'user_id'   => $this->keyFor($user),
            'title'     => $request->input('title'),
            'metadata'  => $request->input('metadata'),
        ]);

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::query()
            ->forUser($user)
            ->findOrFail($id);

        $perPage = $this->resolvePerPage(
            $request->input('per_page'),
            (int) config('chatbot.limits.messages_per_page.default', 50),
            (int) config('chatbot.limits.messages_per_page.max', 200),
        );

        $messages = $conversation->messages()
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage);

        // `MessageResource::collection($paginator)` sólo emite `data`/cursor
        // metadata cuando es el recurso top-level (`->response()`). Anidado en
        // `additional()` se serializaría sin envelope; materializamos el shape
        // completo (`data` + `links` + `meta`) llamando a `response()` aquí.
        $messagesPayload = MessageResource::collection($messages)
            ->response($request)
            ->getData(true);

        return (new ConversationResource($conversation))
            ->additional(['messages' => $messagesPayload])
            ->response();
    }

    public function destroy(Request $request, int $id): Response
    {
        $user = $request->user();

        $conversation = Conversation::query()
            ->forUser($user)
            ->findOrFail($id);

        $conversation->delete();

        return response()->noContent(); // 204
    }

    /**
     * Clampa `per_page` entre [1, $max] aplicando el `$default` si el cliente
     * no envía nada o envía un valor no-positivo. La validación de "entero
     * dentro de rango" para `index` la hace el FormRequest; aquí defendemos
     * los casos donde el FormRequest no aplica (show, edge cases).
     */
    protected function resolvePerPage(mixed $raw, int $default, int $max): int
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        $value = (int) $raw;

        if ($value <= 0) {
            return $default;
        }

        return min($value, $max);
    }

    protected function morphClassFor(mixed $user): string
    {
        if ($user instanceof Model) {
            return $user->getMorphClass();
        }

        return $user !== null ? $user::class : '';
    }

    protected function keyFor(mixed $user): mixed
    {
        if ($user instanceof Model) {
            return $user->getKey();
        }

        return $user?->getAuthIdentifier();
    }
}
