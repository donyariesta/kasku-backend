<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundsAccountMonthlyTarget extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'FundsAccountMonthlyTarget';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = null;

    protected $fillable = [
        'fundsAccountId',
        'amount',
        'effectiveDate',
    ];

    protected $casts = [
        'amount' => 'float',
        'effectiveDate' => 'date',
    ];

    public function fundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'fundsAccountId');
    }
}
