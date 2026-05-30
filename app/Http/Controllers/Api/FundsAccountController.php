<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundsAccountController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = FundsAccount::query()->orderBy('name');

        if ($tenantId) {
            $query->where('tenantId', $tenantId);
        } elseif ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        if ($request->boolean('activeOnly')) {
            $query->where('active', true);
        }

        if ($request->boolean('expenseAllowedOnly')) {
            $query->where('isSystem', false);
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
            'name' => 'required|string|max:255',
            'active' => 'nullable|boolean',
            'monthlyAmount' => 'nullable|numeric|min:0',
        ]);

        try {
            return response()->json(FundsAccount::create([
                ...$payload,
                'tenantId' => $tenantId,
                'active' => $payload['active'] ?? true,
                'monthlyAmount' => $payload['monthlyAmount'] ?? 0,
                'isSystem' => false,
            ]));
        } catch (QueryException) {
            return response()->json(['error' => 'A funds account with this name already exists'], 400);
        }
    }

    public function update(Request $request, string $fundsAccount): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = FundsAccount::query()->where('id', $fundsAccount);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();

        if ($record->isSystem) {
            return response()->json(['error' => 'System funds accounts cannot be edited'], 400);
        }

        $payload = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'active' => 'nullable|boolean',
            'monthlyAmount' => 'nullable|numeric|min:0',
        ]);

        try {
            $record->fill($payload);
            $record->save();
            return response()->json($record);
        } catch (QueryException) {
            return response()->json(['error' => 'A funds account with this name already exists'], 400);
        }
    }

    public function destroy(Request $request, string $fundsAccount): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = FundsAccount::query()->where('id', $fundsAccount);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();

        if ($record->isSystem) {
            return response()->json(['error' => 'System funds accounts cannot be deleted'], 400);
        }

        if ($record->paymentBreakdowns()->exists() || $record->expenses()->exists()) {
            return response()->json(['error' => 'Cannot delete funds account with existing transactions'], 400);
        }

        $record->delete();
        return response()->json(['success' => true]);
    }
}
