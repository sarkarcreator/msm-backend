<?php

namespace App\Http\Requests;

class GeneralStoreSaleRequest extends MobileShopSaleRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $type = strtolower(str_replace([' ', '-'], '_', (string) $user?->business_type));

        return (bool) $user?->license_uuid
            && in_array($type, ['general_store', 'grocery_store', 'shopping_mall', 'retail_shop'], true);
    }
}
