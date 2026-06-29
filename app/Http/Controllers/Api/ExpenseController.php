<?php

namespace App\Http\Controllers\Api;

use App\Repositories\ExpenseRepository;
use App\Models\FundsAccount;
use App\Models\Expense;
use App\Models\ExpenseSourceOfFunds;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $repository = new ExpenseRepository();
        $tenantId = $this->resolveTenantId($request);
        return response()->json($repository->getExpenses(['tenantId' => $tenantId]));
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
            'typeId' => 'required|uuid',
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

        $expense = DB::transaction(function () use ($payload, $tenantId, $request) {
            $expense = Expense::create([
                'title' => $payload['title'],
                'typeId' => $payload['typeId'],
                'description' => $payload['description'],
                'amount' => $payload['amount'],
                'memberId' => $payload['memberId'],
                'date' => $payload['date'] ?? now(),
                'tenantId' => $tenantId,
                'treasurerId' => $request->user()->id,
                'status' => $payload['status'] ?? 'paid',
            ]);

            ExpenseSourceOfFunds::create([
                'expenseId' => $expense->id,
                'amount' => $payload['amount'],
                'fundsAccountId' => $payload['fundsAccountId'],
            ]);

            return $expense;
        });

        return response()->json($expense);
    }

    public function update(Request $request, string $expense): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Expense::query()->where('id', $expense);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $record = $query->firstOrFail();
        $record->fill($request->only(['status']));
        $record->save();

        return response()->json($record);
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
