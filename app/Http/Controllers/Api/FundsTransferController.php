<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Models\FundsTransfer;
use App\Services\FundsAccountBalanceService;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundsTransferController extends BaseApiController
{
    public function __construct(private readonly FundsAccountBalanceService $balanceService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = FundsTransfer::query()
            ->with(['fromFundsAccount', 'toFundsAccount'])
            ->orderByDesc('date');

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
            'fromFundsAccountId' => 'required|uuid|different:toFundsAccountId',
            'toFundsAccountId' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:500',
            'date' => 'nullable|date',
            'month' => 'nullable|integer|min:1|max:12',
            'year' => 'nullable|integer|min:2000|max:2100',
        ]);

        $from = $this->resolveTransferAccount($tenantId, $payload['fromFundsAccountId']);
        if ($from instanceof JsonResponse) {
            return $from;
        }

        $to = $this->resolveTransferAccount($tenantId, $payload['toFundsAccountId']);
        if ($to instanceof JsonResponse) {
            return $to;
        }

        $amount = (float) $payload['amount'];
        $available = $this->balanceService->balance($from);
        if ($amount > $available) {
            return response()->json([
                'error' => 'Insufficient balance in source account',
                'available' => $available,
            ], 400);
        }

        $date = isset($payload['date']) ? Carbon::parse($payload['date']) : now();

        return response()->json(FundsTransfer::create([
            'tenantId' => $tenantId,
            'fromFundsAccountId' => $from->id,
            'toFundsAccountId' => $to->id,
            'amount' => $amount,
            'month' => $payload['month'] ?? (int) $date->month,
            'year' => $payload['year'] ?? (int) $date->year,
            'date' => $date,
            'description' => $payload['description'] ?? null,
        ])->load(['fromFundsAccount', 'toFundsAccount']));
    }

    public function destroy(Request $request, string $fundsTransfer): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = FundsTransfer::query()->where('id', $fundsTransfer);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        $query->delete();

        return response()->json(['success' => true]);
    }

    private function resolveTransferAccount(string $tenantId, string $accountId): FundsAccount|JsonResponse
    {
        $account = FundsAccount::query()
            ->where('id', $accountId)
            ->where('tenantId', $tenantId)
            ->where('active', true)
            ->first();

        if (!$account) {
            return response()->json(['error' => 'Invalid or inactive funds account'], 400);
        }

        if ($account->isSystem) {
            return response()->json(['error' => 'System funds accounts cannot be used for manual transfers'], 400);
        }

        return $account;
    }
}
