<?php

namespace App\Services;

use App\Models\JobRun;
use App\Support\ManagedJobs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JobMonitorService
{
    public function overview(): array
    {
        return [
            'jobs' => array_values(ManagedJobs::all()),
            'queue' => $this->queueSnapshot(),
            'stats' => [
                'pendingQueue' => DB::table('jobs')->count(),
                'failedQueue' => DB::table('failed_jobs')->count(),
                'activeRuns' => JobRun::query()
                    ->whereIn('status', [JobRun::STATUS_QUEUED, JobRun::STATUS_RUNNING])
                    ->count(),
            ],
        ];
    }

    public function listRuns(int $limit = 50): Collection
    {
        return JobRun::query()
            ->with(['triggeredByUser:id,name,username', 'tenant:id,name'])
            ->orderByDesc('createdAt')
            ->limit($limit)
            ->get()
            ->map(fn (JobRun $run) => $this->formatRun($run));
    }

    public function dispatch(string $jobKey, ?string $userId = null, ?string $tenantId = null): array
    {
        $config = ManagedJobs::find($jobKey);
        if (!$config) {
            throw new \InvalidArgumentException('Unknown job key');
        }

        $run = JobRun::create([
            'jobKey' => $jobKey,
            'status' => JobRun::STATUS_QUEUED,
            'trigger' => 'manual',
            'triggeredByUserId' => $userId,
            'tenantId' => $tenantId,
            'queuedAt' => now(),
        ]);

        $jobClass = $config['class'];
        dispatch(new $jobClass(jobRunId: $run->id, tenantId: $tenantId));

        return $this->formatRun($run->fresh(['triggeredByUser:id,name,username', 'tenant:id,name']));
    }

    public function formatRun(JobRun $run): array
    {
        $config = ManagedJobs::find($run->jobKey);

        return [
            'id' => $run->id,
            'jobKey' => $run->jobKey,
            'jobName' => $config['name'] ?? $run->jobKey,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'triggeredByUserId' => $run->triggeredByUserId,
            'triggeredBy' => $run->triggeredByUser ? [
                'id' => $run->triggeredByUser->id,
                'name' => $run->triggeredByUser->name,
                'username' => $run->triggeredByUser->username,
            ] : null,
            'tenantId' => $run->tenantId,
            'tenant' => $run->tenant ? [
                'id' => $run->tenant->id,
                'name' => $run->tenant->name,
            ] : null,
            'result' => $run->result,
            'error' => $run->error,
            'queuedAt' => $run->queuedAt?->toIso8601String(),
            'startedAt' => $run->startedAt?->toIso8601String(),
            'finishedAt' => $run->finishedAt?->toIso8601String(),
            'createdAt' => $run->createdAt?->toIso8601String(),
        ];
    }

    private function queueSnapshot(): array
    {
        $pending = DB::table('jobs')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true) ?? [];

                return [
                    'id' => $row->id,
                    'queue' => $row->queue,
                    'jobName' => $this->shortJobName($payload['displayName'] ?? 'Unknown'),
                    'attempts' => $row->attempts,
                    'reserved' => $row->reserved_at !== null,
                    'availableAt' => $this->unixToIso($row->available_at),
                    'createdAt' => $this->unixToIso($row->created_at),
                ];
            })
            ->values()
            ->all();

        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(25)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true) ?? [];

                return [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'queue' => $row->queue,
                    'jobName' => $this->shortJobName($payload['displayName'] ?? 'Unknown'),
                    'failedAt' => $row->failed_at,
                    'exception' => $this->shortException($row->exception),
                ];
            })
            ->values()
            ->all();

        return [
            'pending' => $pending,
            'failed' => $failed,
        ];
    }

    private function shortJobName(string $displayName): string
    {
        if (str_contains($displayName, '\\')) {
            return class_basename($displayName);
        }

        return $displayName;
    }

    private function shortException(string $exception): string
    {
        $line = strtok($exception, "\n");

        return $line !== false ? $line : $exception;
    }

    private function unixToIso(?int $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        return now()->createFromTimestamp($timestamp)->toIso8601String();
    }
}
