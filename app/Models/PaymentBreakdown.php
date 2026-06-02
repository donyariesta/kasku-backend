<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentBreakdown extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'PaymentBreakdown';
    public $timestamps = false;

    protected $fillable = [
        'paymentId',
        'memberId',
        'amount',
        'fundsAccountId',
        'month',
        'year',
        'notes',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'paymentId');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }

    public function fundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'fundsAccountId');
    }
}
