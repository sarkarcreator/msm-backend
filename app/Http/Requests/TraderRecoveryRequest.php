<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TraderRecoveryRequest extends FormRequest
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
            'recovery_uuid' => ['nullable', 'string', 'max:80'],
            'retailer_uuid' => ['required', 'string', 'max:80'],
            'salesman_uuid' => ['nullable', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:80'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
