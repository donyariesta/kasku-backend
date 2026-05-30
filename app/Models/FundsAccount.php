<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundsAccount extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'FundsAccount';

    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'tenantId',
        'name',
        'active',
        'monthlyAmount',
        'isSystem',
    ];

    protected $casts = [
        'active' => 'boolean',
        'monthlyAmount' => 'float',
        'isSystem' => 'boolean',
    ];

    public const DEPOSIT_NAME = 'Deposit';

    public function isDeposit(): bool
    {
        return $this->isSystem && $this->name === self::DEPOSIT_NAME;
    }

    public function allowsExpenses(): bool
    {
        return !$this->isDeposit();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenantId');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'fundsAccountId');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'fundsAccountId');
    }

    public function transfersOut(): HasMany
    {
        return $this->hasMany(FundsTransfer::class, 'fromFundsAccountId');
    }

    public function transfersIn(): HasMany
    {
        return $this->hasMany(FundsTransfer::class, 'toFundsAccountId');
    }
}
