<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileShopReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->license_uuid && $this->user()?->business_type === 'Mobile Shop';
    }

    public function rules(): array
    {
        return [
            'sale_uuid' => ['nullable', 'string', 'max:80'],
            'purchase_uuid' => ['nullable', 'string', 'max:80'],
            'invoice_number' => ['nullable', 'string', 'max:120'],
            'return_type' => ['nullable', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_uuid' => ['nullable', 'string', 'max:80'],
            'items.*.sale_item_uuid' => ['nullable', 'string', 'max:80'],
            'items.*.purchase_item_uuid' => ['nullable', 'string', 'max:80'],
            'items.*.imei_uuid' => ['nullable', 'string', 'max:80'],
            'items.*.imei' => ['nullable', 'string', 'max:80'],
            'items.*.imei_1' => ['nullable', 'string', 'max:80'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
