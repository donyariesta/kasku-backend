<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundsTransfer extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'FundsTransfer';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'tenantId',
        'fromFundsAccountId',
        'toFundsAccountId',
        'month',
        'year',
        'amount',
        'date',
        'description',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenantId');
    }

    public function fromFundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'fromFundsAccountId');
    }

    public function toFundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'toFundsAccountId');
    }
}
