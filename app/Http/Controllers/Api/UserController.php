<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = User::query()
            ->select(['id', 'username', 'name', 'role', 'createdAt', 'tenantId'])
            ->with('tenant:id,name')
            ->orderByDesc('createdAt');

        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $actor = $request->user();
        $allowedRoles = $actor?->role === Roles::SUPER_ADMIN
            ? [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN, Roles::MEMBER]
            : [Roles::TENANT_ADMIN, Roles::MEMBER];

        $payload = $request->validate([
            'username' => 'required|string|max:255|unique:User,username',
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:255',
            'role' => ['required', 'string', Rule::in($allowedRoles)],
            'tenantId' => 'nullable|uuid|exists:Tenant,id',
        ]);

        $tenantId = $this->resolveTenantIdForUser($actor, $payload['role'], $payload['tenantId'] ?? null);
        if ($tenantId === false) {
            return response()->json(['error' => 'Tenant is required for this role'], 400);
        }

        try {
            $user = User::create([
                'username' => $payload['username'],
                'password' => Hash::make($payload['password']),
                'name' => $payload['name'],
                'role' => $payload['role'],
                'tenantId' => $tenantId,
            ]);

            return response()->json($user->load('tenant:id,name'));
        } catch (QueryException) {
            return response()->json(['error' => 'Username already exists or invalid data'], 400);
        }
    }

    public function update(Request $request, string $user): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $record = $this->findManagedUser($request, $user);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        $actor = $request->user();
        $allowedRoles = $actor?->role === Roles::SUPER_ADMIN
            ? [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN, Roles::MEMBER]
            : [Roles::TENANT_ADMIN, Roles::MEMBER];

        $payload = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('User', 'username')->ignore($record->id)],
            'name' => 'sometimes|required|string|max:255',
            'role' => ['sometimes', 'required', 'string', Rule::in($allowedRoles)],
            'tenantId' => 'nullable|uuid|exists:Tenant,id',
            'password' => 'nullable|string|min:6',
        ]);

        if ($actor?->id === $record->id && isset($payload['role']) && $payload['role'] !== $record->role) {
            return response()->json(['error' => 'You cannot change your own role'], 400);
        }

        if (isset($payload['role'])) {
            $tenantId = $this->resolveTenantIdForUser(
                $actor,
                $payload['role'],
                $payload['tenantId'] ?? $record->tenantId
            );
            if ($tenantId === false) {
                return response()->json(['error' => 'Tenant is required for this role'], 400);
            }
            $record->tenantId = $tenantId;
            $record->role = $payload['role'];
        } elseif (array_key_exists('tenantId', $payload) && $actor?->role === Roles::SUPER_ADMIN) {
            $record->tenantId = $payload['tenantId'];
        }

        if (isset($payload['name'])) {
            $record->name = $payload['name'];
        }
        if (isset($payload['username'])) {
            $record->username = $payload['username'];
        }
        if (!empty($payload['password'])) {
            $record->password = Hash::make($payload['password']);
        }

        try {
            $record->save();

            return response()->json($record->load('tenant:id,name'));
        } catch (QueryException) {
            return response()->json(['error' => 'Username already exists or invalid data'], 400);
        }
    }

    public function updateRole(Request $request, string $user): JsonResponse
    {
        return $this->update($request, $user);
    }

    public function destroy(Request $request, string $user): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $record = $this->findManagedUser($request, $user);
        if ($record instanceof JsonResponse) {
            return $record;
        }

        if ($request->user()?->id === $record->id) {
            return response()->json(['error' => 'You cannot delete your own account'], 400);
        }

        try {
            $record->delete();

            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['error' => 'Cannot delete user: referenced by other records'], 400);
        }
    }

    private function findManagedUser(Request $request, string $userId): User|JsonResponse
    {
        $query = User::query()->where('id', $userId);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->first();
        if (!$record) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return $record;
    }

    /**
     * @return string|null|false tenant id, null for super admin, false if invalid
     */
    private function resolveTenantIdForUser(?User $actor, string $role, ?string $requestedTenantId): string|null|false
    {
        if ($role === Roles::SUPER_ADMIN) {
            return null;
        }

        if ($actor?->role === Roles::TENANT_ADMIN) {
            return $actor->tenantId;
        }

        return $requestedTenantId ?: false;
    }
}
