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
 * CRUD JSON de `Dashboard` (v2.0 / E4, plan §4.7).
 *
 *   - `index`   listado del usuario con `widget_count` agregado para la
 *               sidebar (E5). Ordenado por is_default desc, updated_at desc.
 *   - `store`   crea con slug derivado server-side (`Str::slug(name)` +
 *               sufijo numérico al colisionar dentro del scope del usuario).
 *               Rechaza con 422 si supera `max_dashboards_per_user`.
 *   - `show`    devuelve dashboard + widgets inline (ordenados por
 *               `order_index, id`). Política 404-no-403 para slugs ajenos.
 *   - `update`  rename + set is_default + metadata. Al renombrar regenera
 *               el slug (links viejos rompen pero el comportamiento es la
 *               opción menos sorprendente). El hook `saving` del modelo
 *               auto-demote al resto cuando is_default=true.
 *   - `destroy` soft-delete + auto-promote del próximo (más reciente)
 *               cuando se borra el is_default. Garantiza que el usuario
 *               siempre tiene un default (mientras le quede algún dashboard).
 *
 * Política policy: cualquier slug ajeno o de un dashboard soft-deleted
 * devuelve 404 — `Dashboard::query()->forUser($user)->where('slug',...)
 * ->firstOrFail()` (mismo patrón que `ConversationController`).
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

        // Semántica PATCH: sólo las claves PRESENTES entran al diff. El
        // service regenera slug al renombrar (auto-demote al resto del
        // usuario sigue viviendo en el hook `saving` del modelo).
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
     * Política 404-no-403: cualquier slug ajeno o soft-deleted devuelve
     * 404, no 403. El método `forUser()` + `firstOrFail()` lo aplica.
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
