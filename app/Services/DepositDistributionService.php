<?php

namespace App\Services;

use App\Models\FundsAccount;
use App\Models\FundsTransfer;
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
            return ['tenantId' => $tenant->id, 'transferred' => 0, 'periods' => 0, 'skipped' => 'no_deposit_account'];
        }

        $targetAccounts = FundsAccount::query()
            ->where('tenantId', $tenant->id)
            ->where('active', true)
            ->where('isSystem', false)
            ->where('monthlyAmount', '>', 0)
            ->get();

        if ($targetAccounts->isEmpty()) {
            return ['tenantId' => $tenant->id, 'transferred' => 0, 'periods' => 0, 'skipped' => 'no_target_accounts'];
        }

        $eligiblePayments = $this->eligiblePayments($depositAccount->id, $asOf);
        if ($eligiblePayments->isEmpty()) {
            return ['tenantId' => $tenant->id, 'transferred' => 0, 'periods' => 0, 'skipped' => 'no_eligible_payments'];
        }

        $totalTransferred = 0;
        $periodCount = 0;

        DB::transaction(function () use (
            $tenant,
            $depositAccount,
            $targetAccounts,
            $eligiblePayments,
            $asOf,
            &$totalTransferred,
            &$periodCount
        ): void {
            $grouped = $eligiblePayments->groupBy(fn (Payment $payment) => "{$payment->year}-{$payment->month}");

            foreach ($grouped as $periodKey => $payments) {
                [$year, $month] = array_map('intval', explode('-', $periodKey));
                $periodTotal = $payments->sum('amount');

                if ($periodTotal <= 0) {
                    continue;
                }

                $allocations = $this->allocateByMonthlyAmount($targetAccounts, $periodTotal);
                if ($allocations->isEmpty()) {
                    continue;
                }

                foreach ($allocations as $allocation) {
                    FundsTransfer::create([
                        'tenantId' => $tenant->id,
                        'fromFundsAccountId' => $depositAccount->id,
                        'toFundsAccountId' => $allocation['account']->id,
                        'month' => $month,
                        'year' => $year,
                        'amount' => $allocation['amount'],
                        'date' => $asOf,
                        'description' => sprintf(
                            'Deposit distribution for %02d/%d (%.2f%%)',
                            $month,
                            $year,
                            $allocation['percentage']
                        ),
                    ]);
                }

                Payment::query()
                    ->whereIn('id', $payments->pluck('id'))
                    ->update(['distributedAt' => $asOf]);

                $totalTransferred += $periodTotal;
                $periodCount++;
            }
        });

        Log::info('Deposit distribution completed', [
            'tenantId' => $tenant->id,
            'transferred' => $totalTransferred,
            'periods' => $periodCount,
        ]);

        return [
            'tenantId' => $tenant->id,
            'transferred' => $totalTransferred,
            'periods' => $periodCount,
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
            ->whereNull('distributedAt')
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
