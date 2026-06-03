<?php

namespace App\Models;

class Medicine extends BaseModel
{
    protected $casts = [
        'batch_tracking' => 'boolean',
        'expiry_tracking' => 'boolean',
        'purchase_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'mrp' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'reorder_level' => 'integer',
        'metadata' => 'array',
    ];
}
