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
        'month',
        'year',
        'amount',
        'date',
        'treasurerId',
        'status',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }
}
