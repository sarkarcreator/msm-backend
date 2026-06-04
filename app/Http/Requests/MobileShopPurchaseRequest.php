<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileShopPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->license_uuid && $this->user()?->business_type === 'Mobile Shop';
    }

    public function rules(): array
    {
        return [
            'uuid' => ['nullable', 'string', 'max:80'],
            'supplier_uuid' => ['nullable', 'string', 'max:80'],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'invoice_number' => ['nullable', 'string', 'max:120'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'purchased_at' => ['nullable', 'date'],
            'cart' => ['required_without:items', 'array', 'min:1'],
            'items' => ['required_without:cart', 'array', 'min:1'],
            'cart.*.product_uuid' => ['required_with:cart', 'string', 'max:80'],
            'cart.*.quantity' => ['required_with:cart', 'integer', 'min:1'],
            'cart.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'cart.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            'cart.*.imei' => ['nullable', 'string', 'max:80'],
            'cart.*.imei_1' => ['nullable', 'string', 'max:80'],
            'cart.*.imei_2' => ['nullable', 'string', 'max:80'],
            'cart.*.serial_number' => ['nullable', 'string', 'max:120'],
            'cart.*.imeis' => ['nullable'],
            'items.*.product_uuid' => ['required_with:items', 'string', 'max:80'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
        ];
    }
}
