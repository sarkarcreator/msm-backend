<?php
namespace App\Models;
class Sale extends BaseModel { public function items() { return $this->hasMany(SaleItem::class); } }
