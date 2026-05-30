<?php

namespace App\Jobs;

use App\Services\DepositDistributionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DistributeDepositFundsJob implements ShouldQueue
{
    use Queueable;

    public function handle(DepositDistributionService $service): void
    {
        $results = $service->distributeAll();

        Log::info('DistributeDepositFundsJob finished', [
            'results' => $results,
        ]);
    }
}
