<?php

namespace App\Http\Controllers\Api;

use App\Models\Expense;
use App\Models\FundsAccount;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Expense::query()->with('fundsAccount')->with('member')->orderByDesc('date');
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
            'fundsAccountId' => 'required|uuid',
            'memberId' => 'nullable|uuid',
            'date' => 'nullable|date',
            'status' => 'nullable|string',
        ]);

        if ($error = $this->validateFundsAccount($tenantId, $payload['fundsAccountId'])) {
            return $error;
        }

        return response()->json(Expense::create([
            ...$payload,
            'date' => $payload['date'] ?? now(),
            'tenantId' => $tenantId,
            'treasurerId' => $request->user()->id,
            'status' => $payload['status'] ?? 'paid',
        ])->load('fundsAccount'));
    }

    private function validateFundsAccount(string $tenantId, string $fundsAccountId): ?JsonResponse
    {
        $account = FundsAccount::query()
            ->where('id', $fundsAccountId)
            ->where('tenantId', $tenantId)
            ->where('active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Invalid or inactive funds account'], 400);
        }

        if (!$account->allowsExpenses()) {
            return response()->json(['error' => 'Deposit funds cannot be used for expenses'], 400);
        }

        return null;
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
