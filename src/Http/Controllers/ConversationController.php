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
 * Basic CRUD for conversations (E10 ROADMAP §5/E10).
 *
 *   - `index`:   paginated listing (offset) with search by `title` LIKE `%q%`,
 *                ordered by `updated_at desc` (most recent first).
 *   - `store`:   creates a conversation associated with the authenticated user via the
 *                morph fields (`user_type`+`user_id`), returns 201.
 *   - `show`:    returns the conversation + messages cursor-paginated by id
 *                descending (last first, as the ROADMAP requires).
 *   - `destroy`: soft delete (the migration creates `deleted_at`, the model uses
 *                `SoftDeletes`); responds 204 No Content.
 *
 * Policy / privacy: any access to a foreign conversation
 * returns 404 (not 403) — `Conversation::query()->forUser($user)->findOrFail($id)`.
 * Rationale: 403 leaks existence ("the conversation exists but is not
 * yours"), whereas 404 keeps the foreign conversation indistinguishable from
 * a nonexistent one. Consistent with Laravel's doctrine for policies.
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

        // `MessageResource::collection($paginator)` only emits `data`/cursor
        // metadata when it is the top-level resource (`->response()`). Nested in
        // `additional()` it would serialize without an envelope; we materialize the
        // full shape (`data` + `links` + `meta`) by calling `response()` here.
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
     * Clamps `per_page` to [1, $max], applying the `$default` if the client
     * sends nothing or sends a non-positive value. The "integer
     * within range" validation for `index` is done by the FormRequest; here we defend
     * the cases where the FormRequest does not apply (show, edge cases).
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
