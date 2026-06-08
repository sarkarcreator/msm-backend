<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TraderDeliveryChallanRequest;
use App\Http\Requests\TraderRecoveryRequest;
use App\Http\Requests\TraderSaleRequest;
use App\Services\TradersEnterpriseService;

class TradersEnterpriseController extends Controller
{
    public function __construct(private TradersEnterpriseService $service)
    {
    }

    public function sale(TraderSaleRequest $request)
    {
        return response()->json($this->service->createSale($request->validated(), $request->user()), 201);
    }

    public function recovery(TraderRecoveryRequest $request)
    {
        return response()->json($this->service->createRecovery($request->validated(), $request->user()), 201);
    }

    public function challan(TraderDeliveryChallanRequest $request)
    {
        return response()->json($this->service->createDeliveryChallan($request->validated(), $request->user()), 201);
    }
}
