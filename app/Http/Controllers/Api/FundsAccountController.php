<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Models\Group;
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
        $query = FundsAccount::query()->with('group')->orderBy('name');

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

        if ($request->has('groupId')) {
            $groupId = $request->query('groupId');
            if ($groupId === '' || $groupId === null) {
                $query->whereNull('groupId');
            } else {
                $query->where(function ($q) use ($groupId): void {
                    $q->whereNull('groupId')->orWhere('groupId', $groupId);
                });
            }
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
            'groupId' => 'nullable|uuid',
        ]);

        if ($error = $this->validateGroup($tenantId, $payload['groupId'] ?? null)) {
            return $error;
        }

        try {
            $account = FundsAccount::create([
                'name' => $payload['name'],
                'tenantId' => $tenantId,
                'groupId' => $payload['groupId'] ?? null,
                'active' => $payload['active'] ?? true,
                'monthlyAmount' => 0,
                'isSystem' => false,
            ]);

            $amount = (float) ($payload['monthlyAmount'] ?? 0);
            $effectiveDate = $payload['effectiveDate'] ?? now()->toDateString();
            $this->monthlyTargetService->createTarget($account, $amount, $effectiveDate);

            $account->monthlyAmount = $amount;

            return response()->json($account->load('group'));
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

        $tenantId = $record->tenantId;

        $payload = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'active' => 'nullable|boolean',
            'monthlyAmount' => 'nullable|numeric|min:0',
            'effectiveDate' => 'nullable|date',
            'groupId' => 'nullable|uuid',
        ]);

        if ($error = $this->validateGroup($tenantId, $payload['groupId'] ?? null, array_key_exists('groupId', $payload))) {
            return $error;
        }

        try {
            if (
                array_key_exists('name', $payload)
                || array_key_exists('active', $payload)
                || array_key_exists('groupId', $payload)
            ) {
                $record->fill(collect($payload)->only(['name', 'active', 'groupId'])->all());
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

            return response()->json($record->load('group'));
        } catch (QueryException) {
            return response()->json(['error' => 'A funds account with this name already exists'], 400);
        }
    }

    private function validateGroup(
        string $tenantId,
        ?string $groupId,
        bool $explicitNull = true
    ): ?JsonResponse {
        if (!$explicitNull && $groupId === null) {
            return null;
        }

        if ($groupId === null || $groupId === '') {
            return null;
        }

        $group = Group::query()
            ->where('id', $groupId)
            ->where('tenantId', $tenantId)
            ->first();

        if (!$group) {
            return response()->json(['error' => 'Invalid group for this tenant'], 400);
        }

        return null;
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
