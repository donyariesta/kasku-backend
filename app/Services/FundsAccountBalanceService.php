<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\FundsAccount;
use App\Models\FundsTransfer;
use App\Models\Payment;

class FundsAccountBalanceService
{
    public function balance(FundsAccount $account): float
    {
        $income = Payment::query()
            ->where('fundsAccountId', $account->id)
            ->where('status', 'paid')
            ->sum('amount');

        $spent = Expense::query()
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
