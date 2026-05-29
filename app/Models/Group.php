<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Group';
    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = ['name', 'tenantId'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenantId');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'groupId');
    }
}
