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
}
