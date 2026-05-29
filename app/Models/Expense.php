<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'Expense';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'category',
        'description',
        'amount',
        'date',
        'tenantId',
        'treasurerId',
    ];

    protected $casts = [
        'date' => 'datetime',
    ];
}
