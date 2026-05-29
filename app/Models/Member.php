<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Member extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Member';
    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'name',
        'houseNumber',
        'phoneNumber',
        'status',
        'tenantId',
        'userId',
        'groupId',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupId');
    }
}
