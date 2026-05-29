<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN])) {
            return $forbidden;
        }

        $payload = $request->validate([
            'username' => 'required|string|unique:User,username',
            'password' => 'required|string|min:6',
            'name' => 'required|string',
            'role' => 'required|string',
            'tenantId' => 'nullable|uuid',
        ]);

        try {
            $user = User::create([
                'username' => $payload['username'],
                'password' => Hash::make($payload['password']),
                'name' => $payload['name'],
                'role' => $payload['role'],
                'tenantId' => $payload['role'] === Roles::SUPER_ADMIN ? null : ($payload['tenantId'] ?? null),
            ]);

            return response()->json($user);
        } catch (QueryException) {
            return response()->json(['error' => 'Username already exists or invalid data'], 400);
        }
    }

    public function updateRole(Request $request, string $user): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = User::query()->where('id', $user);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $count = $query->update(['role' => $request->validate(['role' => 'required|string'])['role']]);
        return response()->json(['success' => true, 'count' => $count]);
    }
}
