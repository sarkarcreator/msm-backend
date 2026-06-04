<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MobileShopPurchaseRequest;
use App\Http\Requests\MobileShopReturnRequest;
use App\Http\Requests\MobileShopSaleRequest;
use App\Http\Requests\WarrantyClaimRequest;
use App\Services\MobileShopEnterpriseService;
use Illuminate\Http\Request;

class MobileShopEnterpriseController extends Controller
{
    public function __construct(private MobileShopEnterpriseService $service)
    {
    }

    public function sale(MobileShopSaleRequest $request)
    {
        return response()->json($this->service->createSale($request->validated(), $request->user()), 201);
    }

    public function purchase(MobileShopPurchaseRequest $request)
    {
        return response()->json($this->service->createPurchase($request->validated(), $request->user()), 201);
    }

    public function saleReturn(MobileShopReturnRequest $request)
    {
        return response()->json($this->service->createSaleReturn($request->validated(), $request->user()), 201);
    }

    public function purchaseReturn(MobileShopReturnRequest $request)
    {
        return response()->json($this->service->createPurchaseReturn($request->validated(), $request->user()), 201);
    }

    public function warrantyClaim(WarrantyClaimRequest $request)
    {
        return response()->json($this->service->createWarrantyClaim($request->validated(), $request->user()), 201);
    }

    public function searchImei(Request $request)
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->service->searchImei($request->all(), $request->user());
    }

    public function report(Request $request, string $type)
    {
        return response()->json(['data' => $this->service->reports($type, $request->user())]);
    }
}
