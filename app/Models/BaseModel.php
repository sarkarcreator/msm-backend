<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class BaseModel extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'payload' => 'array',
        'imei_numbers' => 'array',
        'sold_at' => 'datetime',
        'purchased_at' => 'datetime',
        'spent_at' => 'datetime',
        'due_date' => 'date',
    ];
}
