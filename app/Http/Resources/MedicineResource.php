<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MedicineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'brand_name' => $this->brand_name,
            'generic_name' => $this->generic_name,
            'composition' => $this->composition,
            'strength' => $this->strength,
            'dosage_form' => $this->dosage_form,
            'therapeutic_class' => $this->therapeutic_class,
            'manufacturer' => $this->manufacturer,
            'distributor' => $this->distributor,
            'registration_no' => $this->registration_no,
            'barcode' => $this->barcode,
            'pack_size' => $this->pack_size,
            'category' => $this->category,
            'purchase_price' => (float) $this->purchase_price,
            'sale_price' => (float) $this->sale_price,
            'mrp' => (float) $this->mrp,
            'tax_percentage' => (float) $this->tax_percentage,
            'reorder_level' => (int) $this->reorder_level,
            'batch_tracking' => (bool) $this->batch_tracking,
            'expiry_tracking' => (bool) $this->expiry_tracking,
            'status' => $this->status,
            'metadata' => $this->metadata ?: [],
            'created_at' => optional($this->created_at)->toISOString(),
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
