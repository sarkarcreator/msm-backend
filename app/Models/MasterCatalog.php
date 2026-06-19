<?php

namespace App\Models;

class MasterCatalog extends BaseModel
{
    protected $table = 'master_catalogs';

    protected $casts = [
        'metadata' => 'array',
        'default_cost' => 'float',
        'default_price' => 'float',
    ];
}
