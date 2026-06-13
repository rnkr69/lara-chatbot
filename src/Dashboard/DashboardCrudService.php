<?php

declare(strict_types=1);

namespace Rnkr69\LaraChatbot\Dashboard;

use Illuminate\Support\Str;
use Rnkr69\LaraChatbot\Models\Dashboard;

/**
 * v2.2 — Dashboard edit/delete layer shared between:
 *
 *   1. **HTTP controller** (`PATCH/DELETE /chatbot/dashboards/{slug}`).
 *      Historical path — the dashboard sidebar issues renames /
 *      set-default / delete on click.
 *   2. **`EditDashboardTool` / `DeleteDashboardTool`** (conversational
 *      editing v2.2). The LLM applies the same changes requested in natural
 *      language ("rename the dashboard to Operations Q1", "delete the old dashboard").
 *
 * Like `WidgetCrudService`, it does not validate ownership or authorize — the caller
 * resolves the user's dashboard and delegates here only the "apply + persist".
 *
 * The `Dashboard` model's `saving` hook auto-DEMOTES the rest of the user's
 * dashboards when one is marked `is_default=true`, so the service does not
 * orchestrate that part — it only assigns the flag and saves. The post-delete
 * auto-PROMOTE of the user's next dashboard IS implemented by this service (parity
 * with `ApiDashboardController::promoteNextDefault`).
 */
class DashboardCrudService
{
    /**
     * Applies selective changes to the dashboard. PATCH semantics as in
     * `WidgetCrudService`. On rename, the slug is regenerated (parity with
     * the HTTP controller) — the caller must read `new_slug` from the return
     * value when the change applies in order to update URLs.
     *
     * @param  array{name?: string, is_default?: bool, metadata?: array<string,mixed>|null} $changes
     * @return array{applied: array<string, mixed>, new_slug?: string}
     */
    public function update(Dashboard $dashboard, array $changes): array
    {
        $applied = [];
        $newSlug = null;

        if (array_key_exists('name', $changes)) {
            $name = (string) $changes['name'];
            $dashboard->name = $name;

            // Re-derive slug on rename. Keeping the old slug creates a
            // dissonance "the name says X but the URL says Y" that confuses
            // anyone copy/pasting links. If it collides with another dashboard
            // of the same user (not this one), we apply a numeric suffix.
            $derived = $this->deriveUniqueSlug(
                $dashboard->user_type,
                $dashboard->user_id,
                $name,
                $dashboard->id,
            );

            if ($derived !== $dashboard->slug) {
                $newSlug = $derived;
                $dashboard->slug = $derived;
            }

            $applied['name'] = $name;
        }

        if (array_key_exists('is_default', $changes)) {
            $dashboard->is_default = (bool) $changes['is_default'];
            $applied['is_default'] = (bool) $changes['is_default'];
        }

        if (array_key_exists('metadata', $changes)) {
            $dashboard->metadata = $changes['metadata'];
            $applied['metadata'] = $changes['metadata'];
        }

        if ($applied === []) {
            return ['applied' => []];
        }

        $dashboard->save();

        $result = ['applied' => $applied];
        if ($newSlug !== null) {
            $result['new_slug'] = $newSlug;
        }

        return $result;
    }

    /**
     * Soft-delete of the dashboard. If it was the `is_default`, promotes the
     * most recently updated next one to default (parity with
     * `ApiDashboardController::promoteNextDefault`). Returns the slug of the
     * promoted dashboard — useful for the LLM to mention to the user
     * ("deleted X; your default is now Y") — or `null` if there was no next one.
     */
    public function delete(Dashboard $dashboard): ?string
    {
        $wasDefault = (bool) $dashboard->is_default;
        $userType = $dashboard->user_type;
        $userId = $dashboard->user_id;

        $dashboard->delete();

        if (! $wasDefault) {
            return null;
        }

        /** @var Dashboard|null $next */
        $next = Dashboard::query()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($next === null) {
            return null;
        }

        $next->is_default = true;
        $next->save();

        return $next->slug;
    }

    /**
     * Derives a unique slug within the `(user_type, user_id)` scope. Starts
     * with `Str::slug($name)`; on collision, tries `-2`, `-3`… until it finds
     * a free one. `$excludeId` is passed on PATCH so that the dashboard being
     * renamed does not compete against itself. If `Str::slug` produces an
     * empty string (input with only symbols), falls back to `'dashboard'`.
     *
     * `Dashboard::withTrashed()` is deliberate (v2.1.1 #21): the UNIQUE
     * constraint does not exclude soft-deleted rows, so we enumerate those
     * slugs too.
     */
    public function deriveUniqueSlug(string $userType, mixed $userId, string $name, ?int $excludeId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'dashboard';
        }

        if (strlen($base) > 130) {
            $base = substr($base, 0, 130);
        }

        $candidate = $base;
        $suffix    = 2;

        while ($this->slugExists($userType, $userId, $candidate, $excludeId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;

            if ($suffix > 9999) {
                $candidate = $base . '-' . Str::lower(Str::random(8));
                break;
            }
        }

        return $candidate;
    }

    protected function slugExists(string $userType, mixed $userId, string $slug, ?int $excludeId): bool
    {
        return Dashboard::withTrashed()
            ->where('user_type', $userType)
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }
}
