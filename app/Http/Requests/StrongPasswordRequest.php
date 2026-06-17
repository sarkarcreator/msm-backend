<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StrongPasswordRequest extends FormRequest
{
    /**
     * Get the password validation rule with strong requirements.
     */
    protected function getPasswordRule(): array
    {
        return [
            'required',
            'string',
            'min:8',
            'max:72',
            'confirmed', // Requires password_confirmation field
            Password::min(8)
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised(), // Check against HaveIBeenPwned
        ];
    }

    /**
     * Get weak password rule (for backward compatibility where needed).
     */
    protected function getWeakPasswordRule(): array
    {
        return [
            'required',
            'string',
            'min:8',
            'max:72',
            'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).+$/',
        ];
    }
}
