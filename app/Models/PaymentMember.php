<?php

namespace App\Models;

use App\Support\PaymentCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMember extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'PaymentMember';
    public $timestamps = false;

    protected $fillable = [
        'memberId',
        'tenantId',
        'paymentId',
        'month',
        'year',
        'amount',
        'paymentBreakdown',

    ];

    protected $casts = [
        'amount' => 'float',
        'paymentBreakdown' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }
}
