<?php

namespace App\Http\Requests;

class GeneralStorePurchaseRequest extends MobileShopPurchaseRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $type = strtolower(str_replace([' ', '-'], '_', (string) $user?->business_type));

        return (bool) $user?->license_uuid
            && in_array($type, ['general_store', 'grocery_store', 'shopping_mall', 'traders', 'retail_shop'], true);
    }
}
