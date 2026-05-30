<?php

namespace App\Services;

use App\Models\FundsAccount;
use App\Models\Payment;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositDistributionService
{
    public function distributeForTenant(Tenant $tenant, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $depositAccount = FundsAccount::query()
            ->where('tenantId', $tenant->id)
            ->where('isSystem', true)
            ->where('name', FundsAccount::DEPOSIT_NAME)
            ->first();

        if (!$depositAccount) {
            return ['tenantId' => $tenant->id, 'converted' => 0, 'payments' => 0, 'skipped' => 'no_deposit_account'];
        }

        $targetAccounts = FundsAccount::query()
            ->where('tenantId', $tenant->id)
            ->where('active', true)
            ->where('isSystem', false)
            ->where('monthlyAmount', '>', 0)
            ->get();

        if ($targetAccounts->isEmpty()) {
            return ['tenantId' => $tenant->id, 'converted' => 0, 'payments' => 0, 'skipped' => 'no_target_accounts'];
        }

        $eligiblePayments = $this->eligiblePayments($depositAccount->id, $asOf);
        if ($eligiblePayments->isEmpty()) {
            return ['tenantId' => $tenant->id, 'converted' => 0, 'payments' => 0, 'skipped' => 'no_eligible_payments'];
        }

        $totalConverted = 0.0;
        $paymentCount = 0;
        $createdCount = 0;

        DB::transaction(function () use (
            $targetAccounts,
            $eligiblePayments,
            &$totalConverted,
            &$paymentCount,
            &$createdCount
        ): void {
            foreach ($eligiblePayments as $payment) {
                $allocations = $this->allocateByMonthlyAmount($targetAccounts, (float) $payment->amount);
                if ($allocations->isEmpty()) {
                    continue;
                }

                foreach ($allocations as $allocation) {
                    Payment::create([
                        'memberId' => $payment->memberId,
                        'tenantId' => $payment->tenantId,
                        'fundsAccountId' => $allocation['account']->id,
                        'month' => $payment->month,
                        'year' => $payment->year,
                        'amount' => $allocation['amount'],
                        'date' => $payment->date,
                        'treasurerId' => $payment->treasurerId,
                        'status' => $payment->status,
                    ]);
                    $createdCount++;
                }

                $totalConverted += (float) $payment->amount;
                $paymentCount++;
                $payment->delete();
            }
        });

        Log::info('Deposit distribution completed', [
            'tenantId' => $tenant->id,
            'converted' => $totalConverted,
            'payments' => $paymentCount,
            'createdPayments' => $createdCount,
        ]);

        return [
            'tenantId' => $tenant->id,
            'converted' => $totalConverted,
            'payments' => $paymentCount,
            'createdPayments' => $createdCount,
        ];
    }

    public function distributeAll(?Carbon $asOf = null): array
    {
        $results = [];
        Tenant::query()->orderBy('name')->each(function (Tenant $tenant) use (&$results, $asOf): void {
            $results[] = $this->distributeForTenant($tenant, $asOf);
        });

        return $results;
    }

    private function eligiblePayments(string $depositAccountId, Carbon $asOf): Collection
    {
        return Payment::query()
            ->where('fundsAccountId', $depositAccountId)
            ->where('status', 'paid')
            ->where(function ($query) use ($asOf): void {
                $query->where('year', '<', $asOf->year)
                    ->orWhere(function ($inner) use ($asOf): void {
                        $inner->where('year', $asOf->year)
                            ->where('month', '<=', $asOf->month);
                    });
            })
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /**
     * @return Collection<int, array{account: FundsAccount, amount: float, percentage: float}>
     */
    private function allocateByMonthlyAmount(Collection $targetAccounts, float $totalAmount): Collection
    {
        $monthlyTotal = $targetAccounts->sum('monthlyAmount');
        if ($monthlyTotal <= 0) {
            return collect();
        }

        $allocations = collect();
        $assigned = 0.0;
        $lastIndex = $targetAccounts->count() - 1;

        foreach ($targetAccounts->values() as $index => $account) {
            $percentage = ($account->monthlyAmount / $monthlyTotal) * 100;
            $amount = $index === $lastIndex
                ? round($totalAmount - $assigned, 2)
                : round($totalAmount * ($account->monthlyAmount / $monthlyTotal), 2);

            if ($amount <= 0) {
                continue;
            }

            $assigned += $amount;
            $allocations->push([
                'account' => $account,
                'amount' => $amount,
                'percentage' => round($percentage, 2),
            ]);
        }

        return $allocations;
    }
}
