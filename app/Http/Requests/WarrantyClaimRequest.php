<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarrantyClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->license_uuid && $this->user()?->business_type === 'Mobile Shop';
    }

    public function rules(): array
    {
        return [
            'product_uuid' => ['nullable', 'string', 'max:80'],
            'sale_uuid' => ['nullable', 'string', 'max:80'],
            'customer_uuid' => ['nullable', 'string', 'max:80'],
            'imei_uuid' => ['nullable', 'string', 'max:80'],
            'claim_number' => ['nullable', 'string', 'max:120'],
            'issue' => ['nullable', 'string'],
            'issue_description' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:80'],
            'claim_date' => ['nullable', 'date'],
            'resolved_at' => ['nullable', 'date'],
        ];
    }
}
