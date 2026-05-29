<?php

namespace App\Http\Controllers\Api;

use App\Models\Type;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TypeController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Type::query()->orderBy('group')->orderBy('type');

        if ($tenantId) {
            $query->where('tenantId', $tenantId);
        } elseif ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        if ($request->filled('group')) {
            $query->where('group', $request->query('group'));
        }

        return response()->json($query->get());
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

        $payload = $request->validate([
            'group' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            return response()->json(Type::create([
                ...$payload,
                'tenantId' => $tenantId,
            ]));
        } catch (QueryException) {
            return response()->json(['error' => 'Type already exists for this group in this tenant'], 400);
        }
    }

    public function update(Request $request, string $type): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Type::query()->where('id', $type);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();
        $payload = $request->validate([
            'group' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
        ]);

        try {
            $record->fill($payload);
            $record->save();
            return response()->json($record);
        } catch (QueryException) {
            return response()->json(['error' => 'Type already exists for this group in this tenant'], 400);
        }
    }

    public function destroy(Request $request, string $type): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Type::query()->where('id', $type);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $query->delete();
        return response()->json(['success' => true]);
    }
}
