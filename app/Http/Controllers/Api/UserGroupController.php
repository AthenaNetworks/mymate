<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserGroup\SaveUserGroupRequest;
use App\Models\UserGroup;
use App\Support\EngineLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Named operator groups (GitHub #28). A group carries access (read-only everything, or restricted
 * to its maps) that applies to all its members, so an admin sets it once instead of per operator.
 * Every route here is admin-only - group membership decides what someone can see, so it's as much
 * a privilege as is_admin/restricted.
 */
class UserGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = UserGroup::query()
            ->orderBy('name')
            ->get()
            ->map(fn (UserGroup $g) => $this->shape($g));

        return response()->json($groups);
    }

    public function store(SaveUserGroupRequest $request): JsonResponse
    {
        $group = new UserGroup;
        $group->fill($request->safe()->only(['name', 'description']));
        // New groups default to restricted - fail closed if the payload doesn't say.
        $group->restricted = $request->has('restricted') ? $request->boolean('restricted') : true;
        $group->save();
        $this->syncRelations($group, $request);

        $this->log('created', $group, $request);

        return response()->json($this->shape($group), 201);
    }

    public function update(SaveUserGroupRequest $request, UserGroup $userGroup): JsonResponse
    {
        $userGroup->fill($request->safe()->only(['name', 'description']));
        if ($request->has('restricted')) {
            $userGroup->restricted = $request->boolean('restricted');
        }
        $userGroup->save();
        $this->syncRelations($userGroup, $request);

        $this->log('updated', $userGroup, $request);

        return response()->json($this->shape($userGroup));
    }

    /**
     * Refuse to delete a group that still has members. Dropping it would quietly lift their
     * restriction and hand them the whole fleet, which is the wrong way to fail. Move them out
     * first so it's a deliberate choice per operator.
     */
    public function destroy(Request $request, UserGroup $userGroup): Response
    {
        if ($userGroup->users()->exists()) {
            throw ValidationException::withMessages([
                'group' => 'This group still has operators in it - move them out before deleting it.',
            ]);
        }

        $userGroup->delete();
        $this->log('deleted', $userGroup, $request);

        return response()->noContent();
    }

    private function syncRelations(UserGroup $group, Request $request): void
    {
        if ($request->has('map_ids')) {
            $group->maps()->sync(array_map('intval', (array) $request->input('map_ids', [])));
        }
        if ($request->has('user_ids')) {
            $group->users()->sync(array_map('intval', (array) $request->input('user_ids', [])));
        }
    }

    private function log(string $what, UserGroup $group, Request $request): void
    {
        EngineLog::warning("auth: operator group {$what}", [
            'actor_id' => $request->user()->id,
            'group_id' => $group->id,
            'restricted' => (bool) $group->restricted,
            'ip' => $request->ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function shape(UserGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'restricted' => (bool) $group->restricted,
            'map_ids' => $group->maps()->withoutGlobalScopes()->pluck('maps.id')->map(fn ($id) => (int) $id)->all(),
            'user_ids' => $group->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
