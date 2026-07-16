<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ExpenseRepository;
use App\Repositories\FundsAccountRepository;
use App\Repositories\PaymentRepository;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\PaymentCode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ReportController extends BaseApiController
{
    public function getBalances(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $fundsAccountRepository = new FundsAccountRepository();
        $closingBalances = $fundsAccountRepository->getBalanceUntil($tenantId, Carbon::now(), null);

        return response()->json($closingBalances);
    }

    public function getKPI(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'year' => 'required|integer',
            'month' => 'nullable|integer|min:0|max:12',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $paymentRepository = new PaymentRepository();
        $kpi = $paymentRepository->getKPI($tenantId, $payload['year'], $payload['month']?? 0);

        return response()->json($kpi);
    }

    public function getOverdue(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'groupId' => 'nullable|uuid',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $paymentRepository = new PaymentRepository();
        $overdue = $paymentRepository->getOverdue($tenantId, $payload['groupId'] ?? null);
        $overdue = Collect($overdue)->map(function ($item) {
            $periods = collect(json_decode($item->overduePeriods, true))->map(function ($period) {
                return Carbon::parse($period)->format('M Y');
            })->implode(', ');

            return array_merge(Collect($item)->toArray(), ['overduePeriods' => $periods]);
        });

        return response()->json($overdue);
    }

    public function getPaymentSettled(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'groupId' => 'nullable|uuid',
            'year' => 'required|integer',
            'month' => 'nullable|integer|min:0|max:12',
        ]);

        $tenantId = $this->resolveTenantId($request);
        $paymentRepository = new PaymentRepository();
        $paymentSettled = $paymentRepository->getPaymentSettled(
            $tenantId,
            $payload['groupId'] ?? null,
            $payload['year'],
            $payload['month'] ?? 0
        );

        return response()->json($paymentSettled);
    }
}
