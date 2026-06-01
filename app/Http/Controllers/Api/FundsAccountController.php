<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Services\FundsAccountMonthlyTargetService;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundsAccountController extends BaseApiController
{
    public function __construct(
        private readonly FundsAccountMonthlyTargetService $monthlyTargetService,
    ) {}

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

        $month = $request->integer('month') ?: (int) now()->format('n');
        $year = $request->integer('year') ?: (int) now()->format('Y');

        $accounts = $this->monthlyTargetService->attachMonthlyAmounts($query->get(), $month, $year);

        return response()->json($accounts);
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
            'effectiveDate' => 'nullable|date',
        ]);

        try {
            $account = FundsAccount::create([
                'name' => $payload['name'],
                'tenantId' => $tenantId,
                'active' => $payload['active'] ?? true,
                'monthlyAmount' => 0,
                'isSystem' => false,
            ]);

            $amount = (float) ($payload['monthlyAmount'] ?? 0);
            $effectiveDate = $payload['effectiveDate'] ?? now()->toDateString();
            $this->monthlyTargetService->createTarget($account, $amount, $effectiveDate);

            $account->monthlyAmount = $amount;

            return response()->json($account);
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
            'effectiveDate' => 'nullable|date',
        ]);

        try {
            if (array_key_exists('name', $payload) || array_key_exists('active', $payload)) {
                $record->fill(collect($payload)->only(['name', 'active'])->all());
                $record->save();
            }

            if (array_key_exists('monthlyAmount', $payload)) {
                $effectiveDate = $payload['effectiveDate'] ?? now()->toDateString();
                $this->monthlyTargetService->createTarget(
                    $record,
                    (float) $payload['monthlyAmount'],
                    $effectiveDate
                );
            }

            $month = (int) now()->format('n');
            $year = (int) now()->format('Y');
            $this->monthlyTargetService->attachMonthlyAmounts(collect([$record]), $month, $year);

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
