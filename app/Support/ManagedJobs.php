<?php

namespace App\Support;

use App\Jobs\DistributeDepositFundsJob;

class ManagedJobs
{
    public const DEPOSIT_DISTRIBUTE = 'deposit.distribute';

    public static function all(): array
    {
        return [
            self::DEPOSIT_DISTRIBUTE => [
                'key' => self::DEPOSIT_DISTRIBUTE,
                'name' => 'Distribute Deposit Funds',
                'description' => 'Split eligible Deposit payments into target funds accounts by monthlyAmount percentage, then remove the deposit payment records.',
                'schedule' => 'Monthly on the 1st at 01:00',
                'class' => DistributeDepositFundsJob::class,
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
