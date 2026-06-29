<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseSourceOfFunds extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'ExpenseSourceOfFunds';
    public $timestamps = false;
    public static $snakeAttributes = false;

    protected $fillable = [
        'expenseId',
        'amount',
        'fundsAccountId',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expenseId');
    }

    public function fundsAccount(): BelongsTo
    {
        return $this->belongsTo(FundsAccount::class, 'fundsAccountId');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }
}
