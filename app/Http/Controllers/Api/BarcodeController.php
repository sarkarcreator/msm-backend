<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BarcodeRegistryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BarcodeController extends Controller
{
    public function __construct(private BarcodeRegistryService $barcodes)
    {
    }

    public function lookup(Request $request)
    {
        $payload = $request->validate([
            'q' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:0', 'max:25'],
        ]);

        try {
            return response()->json($this->barcodes->lookup($payload['q'], $request->user(), (int) ($payload['limit'] ?? 0)));
        } catch (ValidationException $exception) {
            if ((int) ($payload['limit'] ?? 0) > 0) {
                return response()->json([
                    'match_type' => 'no_match',
                    'scan' => $payload['q'],
                    'product' => null,
                    'imei' => null,
                    'quantity_multiplier' => 1,
                    'results' => $this->barcodes->suggestions($payload['q'], $request->user(), (int) $payload['limit']),
                ]);
            }

            throw $exception;
        }
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
