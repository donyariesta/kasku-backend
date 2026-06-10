<?php

namespace App\Http\Controllers\Api;

use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentBreakdown;
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
            ->whereIn('code', [PaymentCode::MONTHLY_PAYMENT, PaymentCode::COLLECTIVE_PAYMENT])
            ->where('status', 'paid')
            ->with('breakdowns')
            ->whereHas('breakdowns', fn ($q) => $q->where('year', $year))
            ->get();

        $paymentsByMember = collect();
        foreach ($payments as $payment) {
            /** @var PaymentBreakdown $breakdown */
            foreach ($payment->breakdowns as $breakdown) {
                if ((int) $breakdown->year !== $year || empty($breakdown->memberId)) {
                    continue;
                }
                $memberPayments = $paymentsByMember->get($breakdown->memberId, collect());
                if (!$memberPayments->contains(fn (Payment $p) => $p->id === $payment->id)) {
                    $memberPayments->push($payment);
                    $paymentsByMember->put($breakdown->memberId, $memberPayments);
                }
            }
        }

        $result = $members->map(function (Member $member) use ($paymentsByMember, $year) {
            $memberPayments = $paymentsByMember->get($member->id, collect());

            $monthlyPaymentsStatus = [];
            for ($month = 1; $month <= 12; $month++) {
                $payment = $this->findPeriodPayment($memberPayments, $month, $year);
                $monthlyPaymentsStatus[] = [
                    'month' => $month,
                    'year' => $year,
                    'status' => $payment ? 'paid' : 'unpaid',
                    'paidAmount' => $payment ? $this->paidAmountForMemberPeriod($payment, $member->id, $month, $year) : 0.0,
                    'paymentId' => $payment && (int) $payment->code === PaymentCode::MONTHLY_PAYMENT
                        ? $payment->id
                        : null,
                ];
            }

            return [
                'memberId' => $member->id,
                'groupId' => $member->groupId,
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
            $breakdowns = $payment->breakdowns->filter(
                fn (PaymentBreakdown $b) => !empty($b->memberId)
            );
            if ($breakdowns->isEmpty()) {
                return false;
            }

            return $breakdowns->contains(
                fn (PaymentBreakdown $b) =>
                    (int) $b->month === $month
                    && (int) $b->year === $year
            );
        });
    }

    private function paidAmountForMemberPeriod(Payment $payment, string $memberId, int $month, int $year): float
    {
        return (float) $payment->breakdowns
            ->filter(fn (PaymentBreakdown $b) =>
                $b->memberId === $memberId
                && (int) $b->month === $month
                && (int) $b->year === $year
            )
            ->sum('amount');
    }
}
