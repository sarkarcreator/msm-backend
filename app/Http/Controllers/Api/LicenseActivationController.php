<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LicenseActivationController extends Controller
{
    public function activate(Request $request)
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'activation_code' => ['required', 'string'],
            'device_id' => ['required', 'string'],
            'shop_name' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $license = License::where('license_key', $data['license_key'])
            ->where('activation_code', $data['activation_code'])
            ->first();

        if (! $license) {
            throw ValidationException::withMessages(['license_key' => 'Invalid license key or activation code.']);
        }

        if ($license->status !== 'Active') {
            throw ValidationException::withMessages(['license_key' => 'This license is not active.']);
        }

        if ($license->expiry_date && $license->expiry_date !== 'Lifetime' && Carbon::parse($license->expiry_date)->isPast()) {
            throw ValidationException::withMessages(['license_key' => 'This license has expired.']);
        }

        if ($license->device_id && $license->device_id !== $data['device_id']) {
            throw ValidationException::withMessages(['device_id' => 'This license is already bound to another device.']);
        }

        $role = Role::firstOrCreate(['name' => 'Admin'], ['uuid' => (string) Str::uuid()]);
        $shopName = $data['shop_name'] ?? $license->owner_name;
        $businessType = $license->business_type ?: ($license->metadata['business_type'] ?? 'Mobile Shop');
        $user = User::updateOrCreate(
            ['email' => $data['email']],
            [
                'uuid' => User::where('email', $data['email'])->value('uuid') ?: (string) Str::uuid(),
                'name' => $data['name'],
                'password' => $data['password'],
                'role_id' => $role->id,
                'license_uuid' => $license->uuid,
                'business_type' => $businessType,
                'shop_name' => $shopName,
            ]
        );

        $settings = [
            'software_name' => 'Market Sales Management System',
            'shop_name' => $shopName,
            'company_name' => $shopName,
            'business_type' => $businessType,
        ];

        $metadata = array_merge($license->metadata ?: [], [
            'shop_name' => $shopName,
            'business_type' => $businessType,
            'admin_email' => $user->email,
            'activated_device_id' => $data['device_id'],
        ]);

        $license->update([
            'device_id' => $license->device_id ?: $data['device_id'],
            'owner_name' => $license->owner_name ?: ($data['shop_name'] ?? $data['name']),
            'activated_at' => $license->activated_at ?: now(),
            'metadata' => $metadata,
        ]);

        return [
            'license' => $license->fresh(),
            'settings' => $settings,
            'user' => $user->load('role'),
            'token' => $user->createToken('dsh-pos')->plainTextToken,
        ];
    }
}
