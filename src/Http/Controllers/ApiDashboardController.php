<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Rnkr69\LaraChatbot\Dashboard\DashboardCrudService;
use Rnkr69\LaraChatbot\Http\Requests\CreateDashboardRequest;
use Rnkr69\LaraChatbot\Http\Requests\UpdateDashboardRequest;
use Rnkr69\LaraChatbot\Http\Resources\DashboardResource;
use Rnkr69\LaraChatbot\Models\Dashboard;

/**
 * JSON CRUD for `Dashboard` (v2.0 / E4, plan §4.7).
 *
 *   - `index`   user listing with aggregated `widget_count` for the
 *               sidebar (E5). Ordered by is_default desc, updated_at desc.
 *   - `store`   creates with a server-side derived slug (`Str::slug(name)` +
 *               numeric suffix on collision within the user's scope).
 *               Rejects with 422 if it exceeds `max_dashboards_per_user`.
 *   - `show`    returns dashboard + widgets inline (ordered by
 *               `order_index, id`). 404-not-403 policy for foreign slugs.
 *   - `update`  rename + set is_default + metadata. On rename it regenerates
 *               the slug (old links break but this is the least surprising
 *               behavior). The model's `saving` hook auto-demotes the rest
 *               when is_default=true.
 *   - `destroy` soft-delete + auto-promote of the next (most recent) one
 *               when the is_default is deleted. Guarantees the user
 *               always has a default (as long as they have any dashboard left).
 *
 * Policy: any foreign slug or that of a soft-deleted dashboard returns
 * 404 — `Dashboard::query()->forUser($user)->where('slug',...)
 * ->firstOrFail()` (same pattern as `ConversationController`).
 */
class ApiDashboardController extends Controller
{
    public function __construct(
        protected DashboardCrudService $dashboardCrud,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboards = Dashboard::query()
            ->forUser($user)
            ->withCount('widgets')
            ->orderBy('is_default', 'desc')
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return DashboardResource::collection($dashboards)->response();
    }

    public function store(CreateDashboardRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $cap = (int) config('chatbot.dashboard.max_dashboards_per_user', 20);
        $current = Dashboard::query()->forUser($user)->count();

        if ($cap > 0 && $current >= $cap) {
            return response()->json([
                'message' => sprintf(
                    'You have reached the maximum of %d dashboards. Delete one before creating a new one.',
                    $cap,
                ),
                'errors'  => [
                    'name' => [sprintf('Maximum of %d dashboards reached.', $cap)],
                ],
            ], 422);
        }

        $name = (string) $request->input('name');
        $userType = $this->morphClassFor($user);
        $userId = $this->keyFor($user);
        $slug = $this->dashboardCrud->deriveUniqueSlug($userType, $userId, $name);

        $dashboard = Dashboard::create([
            'user_type'      => $userType,
            'user_id'        => $userId,
            'name'           => $name,
            'slug'           => $slug,
            'is_default'     => (bool) $request->input('is_default', false),
            'layout_version' => 1,
            'metadata'       => $request->input('metadata'),
        ]);

        return (new DashboardResource($dashboard->loadCount('widgets')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboard = $this->findOwnedOr404($user, $slug);

        // Eager-load `widgets` so `DashboardResource` nests them *inside*
        // `data` via `whenLoaded`. Earlier `->additional(['widgets' => …])`
        // put them as a sibling of `data`, which the bundle never read.
        $dashboard->load(['widgets' => function ($query): void {
            $query->orderBy('order_index')->orderBy('id');
        }]);

        return (new DashboardResource($dashboard))->response();
    }

    public function update(UpdateDashboardRequest $request, string $slug): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboard = $this->findOwnedOr404($user, $slug);

        // PATCH semantics: only the keys PRESENT enter the diff. The
        // service regenerates the slug on rename (auto-demote of the user's
        // other dashboards still lives in the model's `saving` hook).
        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = (string) $request->input('name');
        }
        if ($request->has('is_default')) {
            $changes['is_default'] = (bool) $request->input('is_default');
        }
        if ($request->has('metadata')) {
            $changes['metadata'] = $request->input('metadata');
        }

        $this->dashboardCrud->update($dashboard, $changes);

        return (new DashboardResource($dashboard->loadCount('widgets')))->response();
    }

    public function destroy(Request $request, string $slug): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $dashboard = $this->findOwnedOr404($user, $slug);

        $this->dashboardCrud->delete($dashboard);

        return response()->noContent(); // 204
    }

    /**
     * 404-not-403 policy: any foreign or soft-deleted slug returns
     * 404, not 403. The `forUser()` + `firstOrFail()` method applies it.
     */
    protected function findOwnedOr404(mixed $user, string $slug): Dashboard
    {
        return Dashboard::query()
            ->forUser($user)
            ->where('slug', $slug)
            ->firstOrFail();
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
