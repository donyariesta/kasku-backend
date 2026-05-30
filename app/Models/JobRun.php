<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRun extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'JobRun';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'jobKey',
        'status',
        'trigger',
        'triggeredByUserId',
        'tenantId',
        'result',
        'error',
        'queuedAt',
        'startedAt',
        'finishedAt',
    ];

    protected $casts = [
        'result' => 'array',
        'queuedAt' => 'datetime',
        'startedAt' => 'datetime',
        'finishedAt' => 'datetime',
    ];

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggeredByUserId');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenantId');
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'startedAt' => now(),
        ]);
    }

    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'finishedAt' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finishedAt' => now(),
        ]);
    }
}
