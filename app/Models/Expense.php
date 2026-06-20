<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Expense';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'category',
        'description',
        'amount',
        'date',
        'status',
        'tenantId',
        'fundsAccountId',
        'treasurerId',
        'memberId',
    ];

    protected $casts = [
        'date' => 'datetime',
        'amount' => 'float',
        'status' => 'string',
    ];

    public function fundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'fundsAccountId');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }
}
