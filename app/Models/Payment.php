<?php

namespace App\Models;

use App\Support\PaymentCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Payment';
    public $timestamps = false;

    protected $fillable = [
        'memberId',
        'tenantId',
        'amount',
        'date',
        'treasurerId',
        'status',
        'code',
        'notes',
        'payorAlias',
    ];

    protected $casts = [
        'date' => 'datetime',
        'amount' => 'float',
        'code' => 'integer',
    ];

    public function isMonthlyPayment(): bool
    {
        return (int) $this->code === PaymentCode::MONTHLY_PAYMENT;
    }

    public function isDonation(): bool
    {
        return (int) $this->code === PaymentCode::DONATION;
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }

    public function breakdowns(): HasMany
    {
        return $this->hasMany(PaymentBreakdown::class, 'paymentId');
    }
}
