<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        return [
            'user' => $user->load('role'),
            'settings' => $this->settingsFor($user),
            'token' => $user->createToken('dsh-pos')->plainTextToken,
        ];
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('role');

        return [
            'user' => $user,
            'settings' => $this->settingsFor($user),
        ];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function settingsFor(User $user): array
    {
        $role = optional($user->role)->name;

        if ($role === 'Super Admin') {
            return [
                'software_name' => 'DSH License Control',
                'shop_name' => 'DSH Digital Solutions Hub',
                'company_name' => 'DSH Digital Solutions Hub',
                'business_type' => 'General Store',
            ];
        }

        $shopName = $user->shop_name ?: 'Retail Shop';
        return [
            'software_name' => 'Market Sales Management System',
            'shop_name' => $shopName,
            'company_name' => $shopName,
            'business_type' => $user->business_type ?: 'General Store',
        ];
    }
}
