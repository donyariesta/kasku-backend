<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Expense::query()->orderByDesc('date');
        if ($tenantId) {
            $query->where('tenantId', $tenantId);
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
            'title' => 'required|string',
            'category' => 'required|string',
            'description' => 'nullable|string',
            'amount' => 'required|numeric',
            'date' => 'nullable|date',
        ]);

        return response()->json(Expense::create([
            ...$payload,
            'date' => $payload['date'] ?? now(),
            'tenantId' => $tenantId,
            'treasurerId' => $request->user()->id,
        ]));
    }

    public function destroy(Request $request, string $expense): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Expense::query()->where('id', $expense);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        try {
            $query->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['error' => 'Cannot delete expense: referenced by other records.'], 400);
        }
    }
}
