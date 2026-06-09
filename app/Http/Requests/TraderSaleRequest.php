<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TraderSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = strtolower(str_replace([' ', '-'], '_', (string) $this->user()?->business_type));
        return (bool) $this->user()?->license_uuid && $type === 'traders';
    }

    public function rules(): array
    {
        return [
            'uuid' => ['nullable', 'string', 'max:80'],
            'retailer_uuid' => ['nullable', 'string', 'max:80'],
            'customer_uuid' => ['nullable', 'string', 'max:80'],
            'salesman_uuid' => ['nullable', 'string', 'max:80'],
            'challan_uuid' => ['nullable', 'string', 'max:80'],
            'vehicle_number' => ['nullable', 'string', 'max:80'],
            'payment_type' => ['nullable', 'string', 'max:50'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'paid' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'sold_at' => ['nullable', 'date'],
            'cart' => ['required_without:items', 'array', 'min:1'],
            'items' => ['required_without:cart', 'array', 'min:1'],
            'cart.*.product_uuid' => ['required_with:cart', 'string', 'max:80'],
            'cart.*.quantity' => ['required_with:cart', 'integer', 'min:1'],
            'cart.*.stock_quantity' => ['nullable', 'integer', 'min:1'],
            'cart.*.selected_unit' => ['nullable', 'string', 'max:80'],
            'cart.*.selected_unit_label' => ['nullable', 'string', 'max:190'],
            'cart.*.conversion_factor' => ['nullable', 'numeric', 'min:1'],
            'cart.*.unit_barcode' => ['nullable', 'string', 'max:190'],
            'cart.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.product_uuid' => ['required_with:items', 'string', 'max:80'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.stock_quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.selected_unit' => ['nullable', 'string', 'max:80'],
            'items.*.selected_unit_label' => ['nullable', 'string', 'max:190'],
            'items.*.conversion_factor' => ['nullable', 'numeric', 'min:1'],
            'items.*.unit_barcode' => ['nullable', 'string', 'max:190'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
