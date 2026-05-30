<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Payment';
    public $timestamps = false;

    protected $fillable = [
        'memberId',
        'tenantId',
        'fundsAccountId',
        'month',
        'year',
        'amount',
        'date',
        'treasurerId',
        'status',
        'distributedAt',
    ];

    protected $casts = [
        'date' => 'datetime',
        'distributedAt' => 'datetime',
        'amount' => 'float',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }

    public function fundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'fundsAccountId');
    }
}
