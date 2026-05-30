<?php

namespace App\Console\Commands;

use App\Services\DepositDistributionService;
use Illuminate\Console\Command;

class DistributeDepositFundsCommand extends Command
{
    protected $signature = 'deposit:distribute {tenantId?}';

    protected $description = 'Distribute deposit payment breakdowns to target funds accounts';

    public function handle(DepositDistributionService $service): int
    {
        $tenantId = $this->argument('tenantId');
        $results = $service->distribute($tenantId);

        $this->info(sprintf('Distributed %d deposit breakdown(s).', count($results)));

        foreach ($results as $result) {
            $this->line(sprintf(
                '  Payment %s: %s → %s',
                $result['paymentId'],
                number_format($result['amount'], 2),
                implode(', ', $result['accounts'])
            ));
        }

        return self::SUCCESS;
    }
}
