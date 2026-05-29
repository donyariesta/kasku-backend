<?php

namespace App\Http\Controllers\Api;

use App\Models\Group;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Group::query()->withCount('members')->orderBy('name');
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $groups = $query->get()->map(function (Group $group) {
            $group->_count = ['members' => $group->members_count];
            unset($group->members_count);
            return $group;
        });

        return response()->json($groups);
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $tenantId = $this->resolveTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID is required'], 400);
        }

        $payload = $request->validate(['name' => 'required|string']);
        $payload['tenantId'] = $tenantId;

        try {
            return response()->json(Group::create($payload));
        } catch (QueryException) {
            return response()->json(['error' => 'Group name already exists in this tenant'], 400);
        }
    }

    public function destroy(Request $request, string $group): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Group::query()->where('id', $group);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        try {
            $query->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['error' => 'Cannot delete group: contains members. Please move members first.'], 400);
        }
    }
}
