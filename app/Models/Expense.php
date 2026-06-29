<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    use HasFactory, HasUuids;
    protected $table = 'Expense';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'typeId',
        'description',
        'amount',
        'date',
        'status',
        'tenantId',
        'treasurerId',
        'memberId',
    ];

    protected $casts = [
        'date' => 'datetime',
        'amount' => 'float',
        'status' => 'string',
    ];

    public function expenseSourceOfFunds(): HasMany
    {
        return $this->hasMany(ExpenseSourceOfFunds::class, 'expenseId')->with('fundsAccount');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'memberId');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(Type::class, 'typeId');
    }
}
