<?php

namespace App\Console\Commands;

use App\Services\DepositDistributionService;
use Illuminate\Console\Command;

class DistributeDepositFundsCommand extends Command
{
    protected $signature = 'deposit:distribute {--tenant= : Limit to a tenant UUID}';

    protected $description = 'Distribute eligible Deposit funds to other accounts by monthlyAmount percentage';

    public function handle(DepositDistributionService $service): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenant = \App\Models\Tenant::query()->findOrFail($tenantId);
            $result = $service->distributeForTenant($tenant);
            $this->info(json_encode($result, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $results = $service->distributeAll();
        foreach ($results as $result) {
            $this->line(json_encode($result));
        }

        return self::SUCCESS;
    }
}
