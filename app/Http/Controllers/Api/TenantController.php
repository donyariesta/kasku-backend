<?php

namespace App\Http\Controllers\Api;

use App\Models\Tenant;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN])) {
            return $forbidden;
        }

        $tenants = Tenant::query()
            ->withCount(['users', 'members'])
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) {
                $tenant->_count = ['users' => $tenant->users_count, 'members' => $tenant->members_count];
                unset($tenant->users_count, $tenant->members_count);
                return $tenant;
            });

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN])) {
            return $forbidden;
        }

        $payload = $request->validate([
            'name' => 'required|string|unique:Tenant,name',
            'slug' => 'required|string|unique:Tenant,slug',
        ]);

        return response()->json(Tenant::create($payload));
    }
}
