<?php
namespace App\Models;
class Product extends BaseModel { public function supplier() { return $this->belongsTo(Supplier::class); } }
