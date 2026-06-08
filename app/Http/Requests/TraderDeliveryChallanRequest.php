<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TraderDeliveryChallanRequest extends FormRequest
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
            'challan_uuid' => ['nullable', 'string', 'max:80'],
            'challan_number' => ['nullable', 'string', 'max:120'],
            'retailer_uuid' => ['nullable', 'string', 'max:80'],
            'retailer_name' => ['nullable', 'string', 'max:190'],
            'salesman_uuid' => ['nullable', 'string', 'max:80'],
            'salesman_name' => ['nullable', 'string', 'max:190'],
            'vehicle_number' => ['nullable', 'string', 'max:80'],
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'max:80'],
        ];
    }
}
