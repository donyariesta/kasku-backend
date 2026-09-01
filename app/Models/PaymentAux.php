<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAux extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'PaymentAux';
    public $timestamps = false;
    public static $snakeAttributes = false;

    protected $fillable = [
        'tenantId',
        'paymentId',
        'month',
        'year',
        'groupId',
        'incentiveAmount',
        'totalMember',
        'amountPerMember',
    ];

    protected $casts = [
        'amountPerMember' => 'float',
        'incentiveAmount' => 'float',
        'totalMember' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'paymentId');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupId');
    }

}
