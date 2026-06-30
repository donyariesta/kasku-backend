<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseSourceOfFunds;
use App\Models\FundsAccount;
use App\Models\FundsTransfer;
use App\Models\PaymentBreakdown;

class FundsAccountBalanceService
{
    public function balance(FundsAccount $account): float
    {
        $income = PaymentBreakdown::query()
            ->where('fundsAccountId', $account->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'paid'))
            ->sum('amount');

        $spent = ExpenseSourceOfFunds::query()
            ->where('fundsAccountId', $account->id)
            ->sum('amount');

        $transferIn = FundsTransfer::query()
            ->where('toFundsAccountId', $account->id)
            ->sum('amount');

        $transferOut = FundsTransfer::query()
            ->where('fromFundsAccountId', $account->id)
            ->sum('amount');

        return (float) $income + (float) $transferIn - (float) $spent - (float) $transferOut;
    }
}
