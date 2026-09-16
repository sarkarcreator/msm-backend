<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BusinessRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessContextController extends Controller
{
    public function __invoke(Request $request, BusinessRegistry $registry): JsonResponse
    {
        $user = $request->user();
        $businessType = $user?->business_type ?: 'General Store';
        $definition = $registry->definition($businessType);

        return response()->json([
            'business' => $definition,
            'license_uuid' => $user?->license_uuid,
            'business_type' => $businessType,
            'business_key' => $definition['id'],
            'role' => $user?->role?->name,
        ]);
    }
}
