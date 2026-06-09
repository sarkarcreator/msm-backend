<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BarcodeRegistryService;
use Illuminate\Http\Request;

class BarcodeController extends Controller
{
    public function __construct(private BarcodeRegistryService $barcodes)
    {
    }

    public function lookup(Request $request)
    {
        $payload = $request->validate([
            'q' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->barcodes->lookup($payload['q'], $request->user()));
    }

    public function receive(Request $request)
    {
        $payload = $request->validate([
            'scan' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'product_uuid' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:1'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'batch_number' => ['nullable', 'string', 'max:120'],
            'expiry_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:190'],
            'invoice_number' => ['nullable', 'string', 'max:190'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        return response()->json($this->barcodes->receive($payload, $request->user()), 201);
    }
}
