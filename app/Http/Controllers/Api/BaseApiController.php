<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BaseApiController extends Controller
{
    protected function ensureRole(Request $request, array $roles): ?JsonResponse
    {
        $role = $request->user()?->role;
        if (!in_array($role, $roles, true)) {
            return response()->json(['error' => 'Forbidden: Insufficient Permissions'], 403);
        }

        return null;
    }

    protected function resolveTenantId(Request $request): ?string
    {
        $user = $request->user();
        if ($user?->role === Roles::SUPER_ADMIN) {
            return $request->query('tenantId') ?: $request->input('tenantId');
        }

        return $user?->tenantId;
    }

    protected function getSetting(Request $request, string $fieldId): ?string
    {
        $tenantId = $this->resolveTenantId($request);
        if (!$tenantId) {
            return null;
        }

        $settingRepository = new \App\Repositories\SettingRepository();

        return $settingRepository->getSetting($tenantId, $fieldId);
    }
}
