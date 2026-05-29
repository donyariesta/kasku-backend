<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Setting';
    public const CREATED_AT = 'createdAt';
    public const UPDATED_AT = 'updatedAt';

    protected $fillable = [
        'tenantId',
        'fieldId',
        'datetimeValue',
        'dateValue',
        'stringValue',
        'booleanValue',
        'numberValue',
        'jsonValue',
        'blobValue',
    ];

    protected $casts = [
        'datetimeValue' => 'datetime',
        'dateValue' => 'date',
        'booleanValue' => 'boolean',
        'jsonValue' => 'array',
    ];
}
