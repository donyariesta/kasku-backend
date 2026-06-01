<?php

namespace App\Http\Controllers\Api;

use App\Models\Member;
use App\Models\Payment;
use App\Support\PaymentCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IuranController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant ID is required'], 400);
        }

        $payload = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'groupId' => 'nullable|uuid',
        ]);

        $year = (int) $payload['year'];

        $membersQuery = Member::query()
            ->where('tenantId', $tenantId)
            ->where('status', 'active')
            ->orderBy('name');

        if (!empty($payload['groupId'])) {
            $membersQuery->where('groupId', $payload['groupId']);
        }

        $members = $membersQuery->get();

        $payments = Payment::query()
            ->where('tenantId', $tenantId)
            ->where('code', PaymentCode::MONTHLY_PAYMENT)
            ->where('status', 'paid')
            ->with('breakdowns')
            ->whereHas('breakdowns', fn ($q) => $q->where('year', $year))
            ->get()
            ->groupBy('memberId');

        $result = $members->map(function (Member $member) use ($payments, $year) {
            $memberPayments = $payments->get($member->id, collect());

            $monthlyPaymentsStatus = [];
            for ($month = 1; $month <= 12; $month++) {
                $payment = $this->findPeriodPayment($memberPayments, $month, $year);
                $monthlyPaymentsStatus[] = [
                    'month' => $month,
                    'year' => $year,
                    'status' => $payment ? 'paid' : 'unpaid',
                    'paidAmount' => $payment ? (float) $payment->amount : 0.0,
                    'paymentId' => $payment?->id,
                ];
            }

            return [
                'memberId' => $member->id,
                'name' => $member->name,
                'address' => $member->houseNumber,
                'monthlyPaymentsStatus' => $monthlyPaymentsStatus,
            ];
        })->values();

        return response()->json($result);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>  $memberPayments
     */
    private function findPeriodPayment($memberPayments, int $month, int $year): ?Payment
    {
        return $memberPayments->first(function (Payment $payment) use ($month, $year) {
            $breakdowns = $payment->breakdowns;
            if ($breakdowns->isEmpty()) {
                return false;
            }

            return $breakdowns->every(
                fn ($b) => (int) $b->month === $month && (int) $b->year === $year
            );
        });
    }
}
