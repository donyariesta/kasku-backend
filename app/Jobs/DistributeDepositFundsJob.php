<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Models\Tenant;
use App\Services\DepositDistributionService;
use App\Support\ManagedJobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DistributeDepositFundsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?string $jobRunId = null,
        public ?string $tenantId = null,
    ) {
    }

    public function handle(DepositDistributionService $service): void
    {
        $run = $this->beginRun();

        try {
            if ($this->tenantId) {
                $tenant = Tenant::query()->findOrFail($this->tenantId);
                $results = [$service->distributeForTenant($tenant)];
            } else {
                $results = $service->distributeAll();
            }

            $run->markCompleted(['results' => $results]);
        } catch (Throwable $exception) {
            $run->markFailed($exception->getMessage());
            throw $exception;
        }
    }

    private function beginRun(): JobRun
    {
        if ($this->jobRunId) {
            $run = JobRun::query()->findOrFail($this->jobRunId);
            $run->markRunning();

            return $run;
        }

        $run = JobRun::create([
            'jobKey' => ManagedJobs::DEPOSIT_DISTRIBUTE,
            'status' => JobRun::STATUS_RUNNING,
            'trigger' => 'schedule',
            'tenantId' => $this->tenantId,
            'queuedAt' => now(),
            'startedAt' => now(),
        ]);

        $this->jobRunId = $run->id;

        return $run;
    }
}
