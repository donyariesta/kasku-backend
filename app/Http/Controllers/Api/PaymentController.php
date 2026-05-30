<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Models\Payment;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Payment::query()->with(['member', 'fundsAccount'])->orderByDesc('date');
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
            'memberId' => 'required|uuid',
            'fundsAccountId' => 'required|uuid',
            'month' => 'required|integer',
            'year' => 'required|integer',
            'amount' => 'required|numeric',
            'date' => 'nullable|date',
            'status' => 'nullable|string',
        ]);

        if ($error = $this->validateFundsAccount($tenantId, $payload['fundsAccountId'])) {
            return $error;
        }

        $payment = Payment::create([
            ...$payload,
            'date' => $payload['date'] ?? now(),
            'tenantId' => $tenantId,
            'treasurerId' => $request->user()->id,
            'status' => $payload['status'] ?? 'paid',
        ]);

        return response()->json($payment->load(['member', 'fundsAccount']));
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

        return null;
    }

    public function destroy(Request $request, string $payment): JsonResponse
    {
        if ($forbidden = $this->ensureRole($request, [Roles::SUPER_ADMIN, Roles::TENANT_ADMIN])) {
            return $forbidden;
        }

        $query = Payment::query()->where('id', $payment);
        if ($request->user()?->role !== Roles::SUPER_ADMIN) {
            $query->where('tenantId', $request->user()?->tenantId);
        }

        try {
            $query->delete();
            return response()->json(['success' => true]);
        } catch (QueryException) {
            return response()->json(['error' => 'Cannot delete payment: referenced by other records.'], 400);
        }
    }
}
