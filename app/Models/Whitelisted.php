<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Whitelisted extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Whitelisted';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'tenantId',
        'memberId',
        'dateFrom',
        'dateTo',
        'typeId',
        'allowance',
        'notes',
    ];

    protected $casts = [
        'dateFrom' => 'date',
        'dateTo' => 'date',
        'allowance' => 'float',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenantId');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'typeId');
    }
}
