<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $medicineId = $this->route('medicine') ?: $this->route('id');

        return [
            'uuid' => ['nullable', 'uuid'],
            'brand_name' => ['required', 'string', 'max:255'],
            'generic_name' => ['nullable', 'string', 'max:255'],
            'composition' => ['nullable', 'string'],
            'strength' => ['nullable', 'string', 'max:120'],
            'dosage_form' => ['nullable', 'string', 'max:120'],
            'therapeutic_class' => ['nullable', 'string', 'max:180'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'distributor' => ['nullable', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:180'],
            'barcode' => ['nullable', 'string', 'max:180', Rule::unique('medicines', 'barcode')->ignore($medicineId, 'uuid')],
            'pack_size' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:180'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'tax_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'batch_tracking' => ['nullable', 'boolean'],
            'expiry_tracking' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(['Active', 'Inactive', 'Discontinued'])],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function payload(): array
    {
        $payload = array_merge([
            'purchase_price' => 0,
            'sale_price' => 0,
            'mrp' => 0,
            'tax_percentage' => 0,
            'reorder_level' => 0,
            'batch_tracking' => true,
            'expiry_tracking' => true,
            'status' => 'Active',
        ], $this->validated());

        foreach (['generic_name', 'composition', 'strength', 'dosage_form', 'therapeutic_class', 'manufacturer', 'distributor', 'registration_no', 'barcode', 'pack_size', 'category'] as $field) {
            if (($payload[$field] ?? null) === '') {
                $payload[$field] = null;
            }
        }

        return $payload;
    }
}
