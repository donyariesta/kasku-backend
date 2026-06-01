<?php

namespace App\Services;

use App\Models\FundsAccount;
use App\Models\FundsAccountMonthlyTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FundsAccountMonthlyTargetService
{
    public function periodStartDate(int $month, int $year): string
    {
        return sprintf('%04d-%02d-01', $year, $month);
    }

    public function amountForAccountAtPeriod(string $fundsAccountId, int $month, int $year): float
    {
        $asOf = $this->periodStartDate($month, $year);

        $target = FundsAccountMonthlyTarget::query()
            ->where('fundsAccountId', $fundsAccountId)
            ->where('effectiveDate', '<=', $asOf)
            ->orderByDesc('effectiveDate')
            ->first();

        return $target ? (float) $target->amount : 0.0;
    }

    /**
     * @param  Collection<int, FundsAccount>|array<string>  $accountsOrIds
     * @return array<string, float> fundsAccountId => amount
     */
    public function amountsForPeriod(Collection|array $accountsOrIds, int $month, int $year): array
    {
        $accountIds = $accountsOrIds instanceof Collection
            ? $accountsOrIds->pluck('id')->all()
            : array_values($accountsOrIds);

        if ($accountIds === []) {
            return [];
        }

        $asOf = $this->periodStartDate($month, $year);

        $targets = FundsAccountMonthlyTarget::query()
            ->whereIn('fundsAccountId', $accountIds)
            ->where('effectiveDate', '<=', $asOf)
            ->orderByDesc('effectiveDate')
            ->get()
            ->groupBy('fundsAccountId');

        $amounts = [];
        foreach ($accountIds as $accountId) {
            $row = $targets->get($accountId)?->first();
            $amounts[$accountId] = $row ? (float) $row->amount : 0.0;
        }

        return $amounts;
    }

    public function attachMonthlyAmounts(Collection $accounts, int $month, int $year): Collection
    {
        $amounts = $this->amountsForPeriod($accounts, $month, $year);

        return $accounts->map(function (FundsAccount $account) use ($amounts) {
            $account->monthlyAmount = $amounts[$account->id] ?? 0.0;

            return $account;
        });
    }

    public function createTarget(
        FundsAccount $account,
        float $amount,
        Carbon|string $effectiveDate
    ): FundsAccountMonthlyTarget {
        return FundsAccountMonthlyTarget::create([
            'fundsAccountId' => $account->id,
            'amount' => $amount,
            'effectiveDate' => $effectiveDate instanceof Carbon
                ? $effectiveDate->toDateString()
                : $effectiveDate,
        ]);
    }
}
