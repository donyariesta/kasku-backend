<?php

namespace App\Services;

use App\Models\FundsAccount;
use App\Models\PaymentBreakdown;
use App\Support\PaymentCode;
use Illuminate\Support\Facades\DB;

class DepositDistributionService
{
    /**
     * Split deposit PaymentBreakdown rows into target-account breakdowns
     * when the payment target month has been reached.
     *
     * @return array<int, array<string, mixed>>
     */
    public function distribute(?string $tenantId = null): array
    {
        $query = FundsAccount::query()
            ->where('isSystem', true)
            ->where('name', FundsAccount::DEPOSIT_NAME);

        if ($tenantId) {
            $query->where('tenantId', $tenantId);
        }

        $results = [];
        foreach ($query->get() as $depositAccount) {
            $results = array_merge($results, $this->distributeForDepositAccount($depositAccount));
        }

        return $results;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function distributeForDepositAccount(FundsAccount $depositAccount): array
    {
        $now = now();
        $currentMonth = (int) $now->format('n');
        $currentYear = (int) $now->format('Y');

        $targetAccounts = FundsAccount::query()
            ->where('tenantId', $depositAccount->tenantId)
            ->where('active', true)
            ->where('isSystem', false)
            ->where('monthlyAmount', '>', 0)
            ->get();

        $totalMonthly = $targetAccounts->sum('monthlyAmount');
        if ($totalMonthly <= 0 || $targetAccounts->isEmpty()) {
            return [];
        }

        $eligibleBreakdowns = PaymentBreakdown::query()
            ->where('fundsAccountId', $depositAccount->id)
            ->where(function ($q) use ($currentYear, $currentMonth): void {
                $q->where('year', '<', $currentYear)
                    ->orWhere(function ($q2) use ($currentYear, $currentMonth): void {
                        $q2->where('year', $currentYear)->where('month', '<=', $currentMonth);
                    });
            })
            ->whereHas('payment', fn ($q) => $q
                ->where('tenantId', $depositAccount->tenantId)
                ->where('status', 'paid')
                ->where('code', PaymentCode::MONTHLY_PAYMENT))
            ->with('payment')
            ->get();

        $distributed = [];

        foreach ($eligibleBreakdowns as $breakdown) {
            $entry = DB::transaction(function () use ($breakdown, $targetAccounts, $totalMonthly) {
                $remaining = (float) $breakdown->amount;
                $accounts = [];

                foreach ($targetAccounts->values() as $index => $account) {
                    $isLast = $index === $targetAccounts->count() - 1;
                    $share = $isLast
                        ? $remaining
                        : round($breakdown->amount * ($account->monthlyAmount / $totalMonthly), 2);

                    if ($share <= 0) {
                        continue;
                    }

                    $remaining -= $share;

                    PaymentBreakdown::create([
                        'paymentId' => $breakdown->paymentId,
                        'amount' => $share,
                        'fundsAccountId' => $account->id,
                        'month' => $breakdown->month,
                        'year' => $breakdown->year,
                        'notes' => 'Distributed from deposit',
                    ]);

                    $accounts[] = $account->name;
                }

                $amount = $breakdown->amount;
                $paymentId = $breakdown->paymentId;
                $breakdown->delete();

                return [
                    'paymentId' => $paymentId,
                    'amount' => $amount,
                    'accounts' => $accounts,
                ];
            });

            $distributed[] = $entry;
        }

        return $distributed;
    }
}
