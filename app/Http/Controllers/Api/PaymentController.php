<?php

namespace App\Http\Controllers\Api;

use App\Models\FundsAccount;
use App\Models\Payment;
use App\Models\PaymentBreakdown;
use App\Support\PaymentCode;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Payment::query()
            ->with(['member', 'breakdowns.fundsAccount'])
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
            'memberId' => 'required|uuid',
            'amount' => 'required|numeric|min:0',
            'date' => 'nullable|date',
            'status' => 'nullable|string',
            'code' => 'nullable|integer|in:1,2',
            'notes' => 'nullable|string',
            'breakdowns' => 'required|array|min:1',
            'breakdowns.*.amount' => 'required|numeric|min:0',
            'breakdowns.*.fundsAccountId' => 'required|uuid',
            'breakdowns.*.month' => 'required|integer|min:1|max:12',
            'breakdowns.*.year' => 'required|integer',
            'breakdowns.*.notes' => 'nullable|string',
        ]);

        $breakdownTotal = collect($payload['breakdowns'])->sum('amount');
        if (round($breakdownTotal, 2) !== round((float) $payload['amount'], 2)) {
            return response()->json(['error' => 'Breakdown amounts must sum to payment amount'], 400);
        }

        foreach ($payload['breakdowns'] as $breakdown) {
            if ($error = $this->validateFundsAccount($tenantId, $breakdown['fundsAccountId'])) {
                return $error;
            }
        }

        $payment = DB::transaction(function () use ($payload, $tenantId, $request) {
            $payment = Payment::create([
                'memberId' => $payload['memberId'],
                'amount' => $payload['amount'],
                'date' => $payload['date'] ?? now(),
                'tenantId' => $tenantId,
                'treasurerId' => $request->user()->id,
                'status' => $payload['status'] ?? 'paid',
                'code' => $payload['code'] ?? PaymentCode::MONTHLY_PAYMENT,
                'notes' => $payload['notes'] ?? null,
            ]);

            foreach ($payload['breakdowns'] as $breakdown) {
                PaymentBreakdown::create([
                    'paymentId' => $payment->id,
                    'amount' => $breakdown['amount'],
                    'fundsAccountId' => $breakdown['fundsAccountId'],
                    'month' => $breakdown['month'],
                    'year' => $breakdown['year'],
                    'notes' => $breakdown['notes'] ?? null,
                ]);
            }

            return $payment;
        });

        return response()->json($payment->load(['member', 'breakdowns.fundsAccount']));
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
