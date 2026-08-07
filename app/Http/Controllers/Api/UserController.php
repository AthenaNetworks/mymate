<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Support\EngineLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Operator account management. Reads are open to any authenticated operator
 * but the payload is *shaped by tier* - a non-admin sees only the roster (name + admin
 * badge, no email/timestamps), an admin sees the full record. Writes (store/update/
 * destroy) are gated by the `admin` route middleware, so this controller assumes the
 * caller is an admin on those actions and only enforces the lockout guards.
 */
class UserController extends Controller
{
    /** Roster - full detail for admins, name + role only for view-only operators. */
    public function index(Request $request): JsonResponse
    {
        $isAdmin = $request->user()->isAdmin();

        $users = User::query()
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->shape($u, $isAdmin));

        return response()->json($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = new User;
        $user->name = $request->validated('name');
        $user->email = $request->validated('email');
        $user->password = $request->validated('password'); // 'hashed' cast
        $user->is_admin = $request->boolean('is_admin'); // explicit - never mass-assigned
        $this->applyAccess($user, $request);
        $user->save();
        $this->syncMaps($user, $request);

        EngineLog::warning('auth: operator created', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'is_admin' => $user->is_admin,
            'ip' => $request->ip(),
        ]);

        return response()->json($this->shape($user, true), 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        // Lockout guard: never demote the system's last admin (would leave nobody able to
        // manage operators - a one-way trap).
        if ($request->has('is_admin') && $user->is_admin && ! $request->boolean('is_admin') && $this->adminCount() <= 1) {
            throw ValidationException::withMessages([
                'is_admin' => 'This is the only administrator - promote another operator before removing admin here.',
            ]);
        }

        if ($request->has('name')) {
            $user->name = $request->validated('name');
        }
        if ($request->has('email')) {
            $user->email = $request->validated('email');
        }
        // Blank/absent password = keep the current one.
        if ($request->filled('password')) {
            $user->password = $request->validated('password'); // 'hashed' cast
        }
        if ($request->has('is_admin')) {
            $user->is_admin = $request->boolean('is_admin'); // explicit - never mass-assigned
        }
        $this->applyAccess($user, $request);
        $user->save();
        $this->syncMaps($user, $request);

        EngineLog::warning('auth: operator updated', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'is_admin' => $user->is_admin,
            'ip' => $request->ip(),
        ]);

        return response()->json($this->shape($user, true));
    }

    public function destroy(Request $request, User $user): Response
    {
        if ($user->id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }

        if ($user->is_admin && $this->adminCount() <= 1) {
            throw ValidationException::withMessages(['user' => 'You cannot delete the only administrator.']);
        }

        $user->delete();

        EngineLog::warning('auth: operator deleted', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        return response()->noContent();
    }

    private function adminCount(): int
    {
        return User::query()->where('is_admin', true)->count();
    }

    /**
     * Apply the restricted-access privilege (GitHub #28). Explicit, never mass-assigned. Restricted
     * and admin are mutually exclusive - an admin sees everything, so restriction is meaningless
     * there and we clear it to avoid a confusing half-state.
     */
    private function applyAccess(User $user, Request $request): void
    {
        if ($request->has('restricted')) {
            $user->restricted = $request->boolean('restricted') && ! $user->is_admin;
        }
        if ($user->is_admin) {
            $user->restricted = false;
        }
    }

    /** Sync the operator's granted maps when the payload carries them (admins have no grants). */
    private function syncMaps(User $user, Request $request): void
    {
        if (! $request->has('map_ids')) {
            return;
        }
        $ids = $user->restricted ? array_map('intval', (array) $request->input('map_ids', [])) : [];
        $user->maps()->sync($ids);
    }

    /**
     * Tier-shaped payload. Passwords are never present (model `$hidden`); a non-admin also
     * never sees email or timestamps - "can view the roster, not sensitive data".
     *
     * @return array<string, mixed>
     */
    private function shape(User $user, bool $forAdmin): array
    {
        $base = [
            'id' => $user->id,
            'name' => $user->name,
            'is_admin' => (bool) $user->is_admin,
        ];

        if (! $forAdmin) {
            return $base;
        }

        return [
            ...$base,
            'restricted' => (bool) $user->restricted,
            'map_ids' => $user->restricted ? $user->maps()->withoutGlobalScopes()->pluck('maps.id')->all() : [],
            'email' => $user->email,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
