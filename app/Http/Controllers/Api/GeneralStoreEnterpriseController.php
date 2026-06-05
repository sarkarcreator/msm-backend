<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeneralStorePurchaseRequest;
use App\Http\Requests\GeneralStoreSaleRequest;
use App\Services\MobileShopEnterpriseService;

class GeneralStoreEnterpriseController extends Controller
{
    public function __construct(private MobileShopEnterpriseService $service)
    {
    }

    public function sale(GeneralStoreSaleRequest $request)
    {
        return response()->json($this->service->createSale($request->validated(), $request->user()), 201);
    }

    public function purchase(GeneralStorePurchaseRequest $request)
    {
        return response()->json($this->service->createPurchase($request->validated(), $request->user()), 201);
    }
}
