<?php

namespace App\Jobs;

use App\Services\DepositDistributionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DistributeDepositFundsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $tenantId = null,
    ) {}

    public function handle(DepositDistributionService $service): void
    {
        $service->distribute($this->tenantId);
    }
}
