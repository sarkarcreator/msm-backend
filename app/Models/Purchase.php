<?php
namespace App\Models;
class Purchase extends BaseModel { public function items() { return $this->hasMany(PurchaseItem::class); } }
