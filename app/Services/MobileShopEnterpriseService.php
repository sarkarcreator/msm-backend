<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashbookEntry;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\ImeiMovement;
use App\Models\ImeiRegistry;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\SyncQueue;
use App\Models\User;
use App\Models\WarrantyClaim;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileShopEnterpriseService
{
    public function createSale(array $payload, User $actor): array
    {
        $this->assertMobileShop($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $items = collect($payload['cart'] ?? $payload['items'] ?? []);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Sale cart is empty.']);
            }

            $customer = $this->customer($payload['customer_uuid'] ?? null, $actor);
            $productRows = Product::where('license_uuid', $actor->license_uuid)
                ->whereIn('uuid', $items->pluck('product_uuid')->filter()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $subtotal = 0;
            $profit = 0;
            $saleItems = [];
            foreach ($items as $item) {
                $product = $productRows->get($item['product_uuid'] ?? '');
                if (! $product) {
                    throw ValidationException::withMessages(['product_uuid' => 'Product not found in this tenant inventory.']);
                }
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $stockQuantity = max(1, (int) ($item['stock_quantity'] ?? ($quantity * max(1, (int) ($item['conversion_factor'] ?? 1)))));
                if ((int) $product->quantity < $stockQuantity) {
                    throw ValidationException::withMessages(['stock' => "{$product->product_name} stock is not enough."]);
                }

                $imei = $this->isMobileShopTenant($actor) ? $this->resolveImeiForSale($item, $product, $actor) : null;
                $price = (float) ($item['price'] ?? $product->sale_price ?? 0);
                $subtotal += $quantity * $price;
                $profit += ($quantity * $price) - ((float) ($product->purchase_price ?? 0) * $stockQuantity);
                $saleItems[] = compact('product', 'imei', 'quantity', 'stockQuantity', 'price', 'item');
            }

            $discount = (float) ($payload['discount'] ?? 0);
            $tax = (float) ($payload['tax'] ?? 0);
            $total = $subtotal - $discount + $tax;
            $paid = (float) ($payload['paid'] ?? 0);
            if (($payload['payment_type'] ?? 'cash') !== 'credit' && $paid <= 0) {
                $paid = $total;
            }
            if (abs($total - (float) ($payload['total'] ?? $total)) > 0.01) {
                throw ValidationException::withMessages(['total' => 'Sale total does not match backend calculation.']);
            }
            $balance = max(0, $total - $paid);
            $now = now();

            $sale = $this->createRecord(new Sale(), [
                'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'invoice_number' => $payload['invoice_number'] ?? $this->nextNumber(Sale::class, 'DSH-INV', $actor),
                'customer_id' => $customer?->id,
                'customer_uuid' => $customer?->uuid,
                'customer_name' => $customer?->name ?? ($payload['customer_name'] ?? 'Walk-in Customer'),
                'payment_type' => $payload['payment_type'] ?? 'cash',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'profit' => $profit - $discount,
                'due_date' => $payload['due_date'] ?? null,
                'sold_at' => $payload['sold_at'] ?? $now,
                'status' => $balance > 0 ? 'Credit Due' : 'Paid',
                'revision' => 1,
            ]);

            $createdItems = [];
            $touchedImeis = [];
            $createdMovements = [];
            foreach ($saleItems as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $imei = $line['imei'];
                $imeiNumbers = $this->isMobileShopTenant($actor) ? $this->imeiNumbersFromItem($line['item'] ?? [], $product) : [];
                $product->update([
                    'quantity' => max(0, (int) $product->quantity - $line['stockQuantity']),
                    'revision' => ((int) ($product->revision ?? 1)) + 1,
                ]);

                $mobileImeiTracking = $this->isMobileShopTenant($actor);
                $saleItem = $this->createRecord(new SaleItem(), [
                    'uuid' => (string) Str::uuid(),
                    'license_uuid' => $actor->license_uuid,
                    'business_type' => $actor->business_type,
                    'sale_id' => $sale->id,
                    'sale_uuid' => $sale->uuid,
                    'product_id' => $product->id,
                    'product_uuid' => $product->uuid,
                    'product_name' => $product->product_name,
                    'imei_uuid' => $imei?->uuid,
                    'imei_1' => $mobileImeiTracking ? ($imei?->imei_1 ?? $product->imei_1 ?? $product->imei ?? null) : null,
                    'imei_2' => $mobileImeiTracking ? ($imei?->imei_2 ?? $product->imei_2 ?? null) : null,
                    'imei_numbers' => $mobileImeiTracking ? ($imei?->imei_numbers ?: $imeiNumbers) : [],
                    'serial_number' => $mobileImeiTracking ? ($imei?->serial_number ?? $product->serial_number ?? null) : null,
                    'imei' => $mobileImeiTracking ? ($imei?->imei_1 ?? $product->imei ?? null) : null,
                    'quantity' => $line['quantity'],
                    'stock_quantity' => $line['stockQuantity'],
                    'selected_unit' => $line['item']['selected_unit'] ?? null,
                    'selected_unit_label' => $line['item']['selected_unit_label'] ?? null,
                    'conversion_factor' => $line['item']['conversion_factor'] ?? null,
                    'unit_barcode' => $line['item']['unit_barcode'] ?? null,
                    'price' => $line['price'],
                    'profit' => ($line['price'] * $line['quantity']) - ((float) ($product->purchase_price ?? 0) * $line['stockQuantity']),
                ]);
                $createdItems[] = $saleItem;
                $this->inventory($actor, $product, 'Stock Out', -$line['stockQuantity'], $sale->invoice_number, 'Sale', $line['item'] ?? []);
                if ($imei) {
                    $createdMovements[] = $this->moveImei($actor, $imei, 'Sale', $sale->invoice_number, 'Sold', [
                        'sale_id' => $sale->id,
                        'sale_uuid' => $sale->uuid,
                        'product_id' => $product->id,
                        'product_uuid' => $product->uuid,
                    ]);
                    $imei->update([
                        'status' => 'Sold',
                        'customer_name' => $sale->customer_name,
                        'invoice_number' => $sale->invoice_number,
                    ]);
                    $touchedImeis[] = $imei->fresh();
                }
            }

            $this->cashbook($actor, 'Sale', $sale->invoice_number, $paid, 0, $sale->uuid);
            if ($customer && $balance > 0) {
                $this->customerLedger($actor, $customer, 'Sale Credit', $balance, $sale->invoice_number, $payload['due_date'] ?? null);
            }
            $this->audit($actor, 'mobile_sale_created', 'sales', $sale->uuid, $sale->toArray());
            $this->queueMany($actor, [
                ['sales', 'create', $sale],
                ...collect($createdItems)->map(fn ($item) => ['sale_items', 'create', $item])->all(),
                ...collect($saleItems)->map(fn ($line) => ['products', 'update', $line['product']])->all(),
                ...collect($touchedImeis)->map(fn ($imei) => ['imei_registry', 'update', $imei])->all(),
                ...collect($createdMovements)->map(fn ($movement) => ['imei_movements', 'create', $movement])->all(),
            ]);

            return [
                'sale' => $sale->fresh(),
                'sale_items' => collect($createdItems)->map->fresh()->values(),
                'products' => collect($saleItems)->map(fn ($line) => $line['product']->fresh())->values(),
                'imeis' => collect($touchedImeis)->map->fresh()->values(),
                'imei_movements' => collect($createdMovements)->map->fresh()->values(),
            ];
        }, 3);
    }

    public function createPurchase(array $payload, User $actor): array
    {
        $this->assertMobileShop($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $items = collect($payload['cart'] ?? $payload['items'] ?? []);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Purchase cart is empty.']);
            }

            $supplier = $this->supplier($payload['supplier_uuid'] ?? null, $actor);
            if ($this->hasColumn('purchases', 'supplier_id') && ! $supplier?->id) {
                throw ValidationException::withMessages(['supplier_uuid' => 'Supplier is required by the legacy purchase schema.']);
            }
            $productRows = Product::where('license_uuid', $actor->license_uuid)
                ->whereIn('uuid', $items->pluck('product_uuid')->filter()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');
            $total = $items->sum(fn ($item) => max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['cost_price'] ?? $item['purchase_price'] ?? 0));
            $paid = (float) ($payload['paid'] ?? 0);
            $balance = max(0, $total - $paid);

            $purchase = $this->createRecord(new Purchase(), [
                'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'supplier_id' => $supplier?->id,
                'supplier_uuid' => $supplier?->uuid,
                'supplier_name' => $supplier?->supplier_name ?? ($payload['supplier_name'] ?? null),
                'invoice_number' => $payload['invoice_number'] ?? $this->nextNumber(Purchase::class, 'PUR', $actor),
                'total' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'status' => $balance > 0 ? 'Payable' : 'Paid',
                'purchased_at' => $payload['purchased_at'] ?? now(),
                'revision' => 1,
            ]);

            $createdItems = [];
            $createdImeis = [];
            $createdMovements = [];
            foreach ($items as $item) {
                $product = $productRows->get($item['product_uuid'] ?? '');
                if (! $product) {
                    throw ValidationException::withMessages(['product_uuid' => 'Product not found in this tenant inventory.']);
                }
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $stockQuantity = max(1, (int) ($item['stock_quantity'] ?? ($quantity * max(1, (int) ($item['conversion_factor'] ?? 1)))));
                $cost = (float) ($item['cost_price'] ?? $item['purchase_price'] ?? 0);
                $product->update([
                    'purchase_price' => $cost,
                    'quantity' => (int) $product->quantity + $stockQuantity,
                    'revision' => ((int) ($product->revision ?? 1)) + 1,
                ]);
                $purchaseItem = $this->createRecord(new PurchaseItem(), [
                    'uuid' => (string) Str::uuid(),
                    'license_uuid' => $actor->license_uuid,
                    'business_type' => $actor->business_type,
                    'purchase_id' => $purchase->id,
                    'purchase_uuid' => $purchase->uuid,
                    'product_id' => $product->id,
                    'product_uuid' => $product->uuid,
                    'product_name' => $product->product_name,
                    'quantity' => $quantity,
                    'stock_quantity' => $stockQuantity,
                    'selected_unit' => $item['selected_unit'] ?? null,
                    'selected_unit_label' => $item['selected_unit_label'] ?? null,
                    'conversion_factor' => $item['conversion_factor'] ?? null,
                    'unit_barcode' => $item['unit_barcode'] ?? null,
                    'purchase_price' => $cost,
                    'cost_price' => $cost,
                ]);
                $createdItems[] = $purchaseItem;
                $this->inventory($actor, $product, 'Stock In', $stockQuantity, $purchase->invoice_number, 'Purchase', $item);
                if ($this->isMobileShopTenant($actor)) {
                    foreach ($this->imeiRowsFromItem($item) as $imeiRow) {
                        $imei = $this->createImei($actor, $product, $imeiRow);
                        $createdImeis[] = $imei;
                        $createdMovements[] = $this->moveImei($actor, $imei, 'Purchase', $purchase->invoice_number, 'In Stock', [
                            'purchase_id' => $purchase->id,
                            'purchase_uuid' => $purchase->uuid,
                            'product_id' => $product->id,
                            'product_uuid' => $product->uuid,
                        ]);
                    }
                }
            }

            if ($supplier && $balance > 0) {
                $this->supplierLedger($actor, $supplier, 'Purchase Payable', $balance, $purchase->invoice_number);
            }
            $this->audit($actor, 'mobile_purchase_created', 'purchases', $purchase->uuid, $purchase->toArray());
            $this->queueMany($actor, [
                ['purchases', 'create', $purchase],
                ...collect($createdItems)->map(fn ($item) => ['purchase_items', 'create', $item])->all(),
                ...$productRows->values()->map(fn ($product) => ['products', 'update', $product])->all(),
                ...collect($createdImeis)->map(fn ($imei) => ['imei_registry', 'create', $imei])->all(),
                ...collect($createdMovements)->map(fn ($movement) => ['imei_movements', 'create', $movement])->all(),
            ]);

            return [
                'purchase' => $purchase->fresh(),
                'purchase_items' => collect($createdItems)->map->fresh()->values(),
                'products' => $productRows->values()->map->fresh()->values(),
                'imeis' => collect($createdImeis)->map->fresh()->values(),
                'imei_movements' => collect($createdMovements)->map->fresh()->values(),
            ];
        }, 3);
    }

    public function createSaleReturn(array $payload, User $actor): array
    {
        $this->assertMobileShop($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $sale = Sale::where('license_uuid', $actor->license_uuid)
                ->where(fn ($query) => $query->where('uuid', $payload['sale_uuid'] ?? '')->orWhere('invoice_number', $payload['invoice_number'] ?? ''))
                ->lockForUpdate()
                ->firstOrFail();
            $items = collect($payload['items'] ?? []);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Return items are required.']);
            }
            $refund = $items->sum(fn ($item) => max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['amount'] ?? $item['price'] ?? 0));
            $return = $this->createRecord(new SaleReturn(), [
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'sale_id' => $sale->id,
                'sale_uuid' => $sale->uuid,
                'customer_id' => $sale->customer_id ?? null,
                'customer_uuid' => $sale->customer_uuid,
                'return_number' => $payload['return_number'] ?? $this->nextNumber(SaleReturn::class, 'SRN', $actor),
                'invoice_number' => $sale->invoice_number,
                'return_type' => $payload['return_type'] ?? 'Refund',
                'subtotal' => $refund,
                'refund_amount' => $refund,
                'status' => 'Completed',
                'returned_at' => now(),
            ]);
            $created = [];
            foreach ($items as $item) {
                $product = Product::where('license_uuid', $actor->license_uuid)->where('uuid', $item['product_uuid'] ?? '')->lockForUpdate()->first();
                if ($product) {
                    $product->update(['quantity' => (int) $product->quantity + max(1, (int) ($item['quantity'] ?? 1))]);
                    $this->inventory($actor, $product, 'Stock In', max(1, (int) ($item['quantity'] ?? 1)), $return->return_number, 'Sale Return');
                }
                $imei = $this->findImei($actor, $item['imei_uuid'] ?? null, $item['imei_1'] ?? $item['imei'] ?? null);
                if ($imei) {
                    $this->moveImei($actor, $imei, 'Return', $return->return_number, 'Returned', [
                        'sale_id' => $sale->id,
                        'sale_uuid' => $sale->uuid,
                        'product_id' => $product?->id,
                        'product_uuid' => $product?->uuid,
                    ]);
                    $imei->update(['status' => 'Returned']);
                }
                $saleItem = $this->saleItem($item['sale_item_uuid'] ?? null, $actor);
                $created[] = $this->createRecord(new SaleReturnItem(), [
                    'uuid' => (string) Str::uuid(),
                    'license_uuid' => $actor->license_uuid,
                    'business_type' => $actor->business_type,
                    'sale_return_id' => $return->id,
                    'sale_return_uuid' => $return->uuid,
                    'sale_item_id' => $saleItem?->id,
                    'sale_item_uuid' => $item['sale_item_uuid'] ?? null,
                    'product_id' => $product?->id,
                    'product_uuid' => $item['product_uuid'] ?? null,
                    'imei_id' => $imei?->id,
                    'imei_uuid' => $imei?->uuid,
                    'product_name' => $item['product_name'] ?? $product?->product_name,
                    'imei_1' => $imei?->imei_1 ?? ($item['imei_1'] ?? null),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'amount' => (float) ($item['amount'] ?? $item['price'] ?? 0),
                ]);
            }
            if ($sale->customer_uuid) {
                $customer = $this->customer($sale->customer_uuid, $actor);
                if ($customer) {
                    $this->customerLedger($actor, $customer, 'Sale Return', -$refund, $return->return_number, null);
                }
            }
            $this->audit($actor, 'mobile_sale_return_created', 'sale_returns', $return->uuid, $return->toArray());
            return ['sale_return' => $return->fresh(), 'sale_return_items' => collect($created)->map->fresh()->values()];
        }, 3);
    }

    public function createPurchaseReturn(array $payload, User $actor): array
    {
        $this->assertMobileShop($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $purchase = Purchase::where('license_uuid', $actor->license_uuid)
                ->where(fn ($query) => $query->where('uuid', $payload['purchase_uuid'] ?? '')->orWhere('invoice_number', $payload['invoice_number'] ?? ''))
                ->lockForUpdate()
                ->firstOrFail();
            $items = collect($payload['items'] ?? []);
            $total = $items->sum(fn ($item) => max(1, (int) ($item['quantity'] ?? 1)) * (float) ($item['amount'] ?? $item['cost_price'] ?? 0));
            $return = $this->createRecord(new PurchaseReturn(), [
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'purchase_id' => $purchase->id,
                'purchase_uuid' => $purchase->uuid,
                'supplier_id' => $purchase->supplier_id ?? null,
                'supplier_uuid' => $purchase->supplier_uuid,
                'return_number' => $payload['return_number'] ?? $this->nextNumber(PurchaseReturn::class, 'PRN', $actor),
                'invoice_number' => $purchase->invoice_number,
                'total' => $total,
                'status' => 'Completed',
                'returned_at' => now(),
            ]);
            $created = [];
            foreach ($items as $item) {
                $product = Product::where('license_uuid', $actor->license_uuid)->where('uuid', $item['product_uuid'] ?? '')->lockForUpdate()->first();
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                if ($product) {
                    if ((int) $product->quantity < $quantity) {
                        throw ValidationException::withMessages(['stock' => "{$product->product_name} stock is not enough for purchase return."]);
                    }
                    $product->update(['quantity' => (int) $product->quantity - $quantity]);
                    $this->inventory($actor, $product, 'Stock Out', -$quantity, $return->return_number, 'Purchase Return');
                }
                $purchaseItem = $this->purchaseItem($item['purchase_item_uuid'] ?? null, $actor);
                $created[] = $this->createRecord(new PurchaseReturnItem(), [
                    'uuid' => (string) Str::uuid(),
                    'license_uuid' => $actor->license_uuid,
                    'business_type' => $actor->business_type,
                    'purchase_return_id' => $return->id,
                    'purchase_return_uuid' => $return->uuid,
                    'purchase_item_id' => $purchaseItem?->id,
                    'purchase_item_uuid' => $item['purchase_item_uuid'] ?? null,
                    'product_id' => $product?->id,
                    'product_uuid' => $item['product_uuid'] ?? null,
                    'imei_id' => null,
                    'product_name' => $item['product_name'] ?? $product?->product_name,
                    'quantity' => $quantity,
                    'amount' => (float) ($item['amount'] ?? $item['cost_price'] ?? 0),
                ]);
            }
            if ($purchase->supplier_uuid) {
                $supplier = $this->supplier($purchase->supplier_uuid, $actor);
                if ($supplier) {
                    $this->supplierLedger($actor, $supplier, 'Purchase Return', -$total, $return->return_number);
                }
            }
            $this->audit($actor, 'mobile_purchase_return_created', 'purchase_returns', $return->uuid, $return->toArray());
            return ['purchase_return' => $return->fresh(), 'purchase_return_items' => collect($created)->map->fresh()->values()];
        }, 3);
    }

    public function createWarrantyClaim(array $payload, User $actor): WarrantyClaim
    {
        $this->assertMobileShop($actor);
        if (! isset($payload['issue']) && isset($payload['issue_description'])) {
            $payload['issue'] = $payload['issue_description'];
        }
        $product = $payload['product_uuid'] ?? null
            ? Product::where('license_uuid', $actor->license_uuid)->where('uuid', $payload['product_uuid'])->first()
            : null;
        $sale = $payload['sale_uuid'] ?? null
            ? Sale::where('license_uuid', $actor->license_uuid)->where('uuid', $payload['sale_uuid'])->first()
            : null;
        $customer = $payload['customer_uuid'] ?? null
            ? Customer::where('license_uuid', $actor->license_uuid)->where('uuid', $payload['customer_uuid'])->first()
            : null;
        $imei = ! empty($payload['imei_uuid'])
            ? $this->findImei($actor, $payload['imei_uuid'], null)
            : null;
        $claim = $this->createRecord(new WarrantyClaim(), array_merge($payload, [
            'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'product_id' => $product?->id,
            'sale_id' => $sale?->id,
            'customer_id' => $customer?->id,
            'imei_id' => $imei?->id,
            'claim_number' => $payload['claim_number'] ?? $this->nextNumber(WarrantyClaim::class, 'WCL', $actor),
            'claim_date' => $payload['claim_date'] ?? now()->toDateString(),
            'status' => $payload['status'] ?? 'Open',
        ]));
        if ($imei) {
            $this->moveImei($actor, $imei, 'Warranty Claim', $claim->uuid, $imei->status ?: 'In Stock', [
                'warranty_claim_id' => $claim->id,
                'warranty_claim_uuid' => $claim->uuid,
                'product_id' => $product?->id,
                'product_uuid' => $product?->uuid,
                'sale_id' => $sale?->id,
                'sale_uuid' => $sale?->uuid,
            ]);
        }
        $this->audit($actor, 'warranty_claim_created', 'warranty_claims', $claim->uuid, $claim->toArray());
        return $claim->fresh();
    }

    public function searchImei(array $filters, User $actor)
    {
        $this->assertMobileShop($actor);
        $query = ImeiRegistry::where('license_uuid', $actor->license_uuid);
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('imei_1', 'like', "%{$term}%")
                    ->orWhere('imei_2', 'like', "%{$term}%")
                    ->orWhere('serial_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('invoice_number', 'like', "%{$term}%");
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        return $query->latest('updated_at')->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function reports(string $type, User $actor): array
    {
        $this->assertMobileShop($actor);
        $license = $actor->license_uuid;
        return match ($type) {
            'daily-sales' => Sale::where('license_uuid', $license)->whereDate('sold_at', now())->get()->all(),
            'monthly-sales' => Sale::where('license_uuid', $license)->where('sold_at', '>=', now()->startOfMonth())->get()->all(),
            'profit' => Sale::where('license_uuid', $license)->select('invoice_number', 'customer_name', 'total', 'profit', 'sold_at')->latest('sold_at')->limit(500)->get()->all(),
            'brand' => Product::where('license_uuid', $license)->selectRaw('brand, COUNT(*) as products, SUM(quantity) as stock')->groupBy('brand')->get()->all(),
            'category' => Product::where('license_uuid', $license)->selectRaw('category, COUNT(*) as products, SUM(quantity) as stock')->groupBy('category')->get()->all(),
            'inventory' => Product::where('license_uuid', $license)->latest('updated_at')->limit(1000)->get()->all(),
            'low-stock' => Product::where('license_uuid', $license)->whereColumn('quantity', '<=', 'low_stock_threshold')->get()->all(),
            'imei-sales' => ImeiRegistry::where('license_uuid', $license)->where('status', 'Sold')->latest('updated_at')->limit(1000)->get()->all(),
            'customer-ledger' => CustomerLedger::where('license_uuid', $license)->latest('entry_at')->limit(1000)->get()->all(),
            'supplier-ledger' => SupplierLedger::where('license_uuid', $license)->latest('entry_at')->limit(1000)->get()->all(),
            'repair-performance' => \App\Models\Repair::where('license_uuid', $license)->selectRaw('technician, status, COUNT(*) as jobs, SUM(charges) as charges')->groupBy('technician', 'status')->get()->all(),
            'warranty' => WarrantyClaim::where('license_uuid', $license)->latest('claim_date')->limit(1000)->get()->all(),
            'returns' => SaleReturn::where('license_uuid', $license)->latest('returned_at')->limit(500)->get()->all(),
            default => [],
        };
    }

    private function resolveImeiForSale(array $item, Product $product, User $actor): ?ImeiRegistry
    {
        $numbers = $this->imeiNumbersFromItem($item, $product);
        $imeiValue = $item['imei_uuid'] ?? $item['imei_1'] ?? $item['imei'] ?? ($numbers[0] ?? null) ?? $product->imei_1 ?? $product->imei ?? null;
        if (! $imeiValue) {
            return null;
        }
        $imei = $this->findImei($actor, $item['imei_uuid'] ?? null, $imeiValue);
        if (! $imei) {
            $imei = $this->createImei($actor, $product, [
                'imei_1' => $imeiValue,
                'imei_2' => $item['imei_2'] ?? ($numbers[1] ?? null),
                'imei_numbers' => $numbers,
                'serial_number' => $item['serial_number'] ?? $product->serial_number ?? null,
            ]);
        }
        if ($imei->status === 'Sold') {
            throw ValidationException::withMessages(['imei' => "IMEI {$imeiValue} is already sold."]);
        }
        return $imei;
    }

    private function createImei(User $actor, Product $product, array $payload): ImeiRegistry
    {
        $numbers = $this->normalizeImeiNumbers($payload['imei_numbers'] ?? [$payload['imei_1'] ?? null, $payload['imei_2'] ?? null]);
        foreach ($numbers as $number) {
            $query = ImeiRegistry::where('license_uuid', $actor->license_uuid)->where(function ($q) use ($number) {
                $q->where('imei_1', $number)->orWhere('imei_2', $number)->orWhere('serial_number', $number);
                if (Schema::hasColumn('imei_registry', 'imei_numbers')) {
                    $q->orWhereJsonContains('imei_numbers', $number);
                }
            });
            if ($query->exists()) {
                throw ValidationException::withMessages(['imei' => "Duplicate IMEI {$number}."]);
            }
        }
        return $this->createRecord(new ImeiRegistry(), [
            'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'product_name' => $product->product_name,
            'imei_1' => $payload['imei_1'] ?? ($numbers[0] ?? null),
            'imei_2' => $payload['imei_2'] ?? ($numbers[1] ?? null),
            'imei_numbers' => $numbers,
            'serial_number' => $payload['serial_number'] ?? null,
            'status' => 'In Stock',
        ]);
    }

    private function findImei(User $actor, ?string $uuid, ?string $value): ?ImeiRegistry
    {
        return ImeiRegistry::where('license_uuid', $actor->license_uuid)
            ->where(function ($query) use ($uuid, $value) {
                if ($uuid) {
                    $query->where('uuid', $uuid);
                }
                if ($value) {
                    $query->orWhere('imei_1', $value)->orWhere('imei_2', $value)->orWhere('serial_number', $value);
                    if (Schema::hasColumn('imei_registry', 'imei_numbers')) {
                        $query->orWhereJsonContains('imei_numbers', $value);
                    }
                }
            })
            ->lockForUpdate()
            ->first();
    }

    private function imeiRowsFromItem(array $item): array
    {
        $rows = $item['imeis'] ?? $item['imei_numbers'] ?? null;
        if (is_string($rows)) {
            $rows = collect(preg_split('/\r?\n/', $rows))->map(fn ($line) => ['imei_1' => trim($line)])->filter(fn ($line) => $line['imei_1'])->values()->all();
        }
        if (is_array($rows)) {
            if (array_is_list($rows) && collect($rows)->every(fn ($row) => is_string($row) || is_numeric($row))) {
                return collect($rows)->map(fn ($value) => ['imei_1' => trim((string) $value)])->filter(fn ($row) => $row['imei_1'])->values()->all();
            }
            return collect($rows)->map(function ($row) {
                $numbers = $this->normalizeImeiNumbers($row['imei_numbers'] ?? null);
                return array_merge($row, [
                    'imei_1' => $row['imei_1'] ?? ($numbers[0] ?? null),
                    'imei_2' => $row['imei_2'] ?? ($numbers[1] ?? null),
                    'imei_numbers' => $numbers,
                ]);
            })->values()->all();
        }
        $numbers = $this->imeiNumbersFromItem($item, null);
        return array_filter([[
            'imei_1' => $item['imei_1'] ?? $item['imei'] ?? ($numbers[0] ?? null),
            'imei_2' => $item['imei_2'] ?? ($numbers[1] ?? null),
            'imei_numbers' => $numbers,
            'serial_number' => $item['serial_number'] ?? null,
        ]], fn ($row) => $row['imei_1'] || $row['imei_2'] || $row['serial_number'] || $row['imei_numbers']);
    }

    private function imeiNumbersFromItem(array $item, ?Product $product): array
    {
        return $this->normalizeImeiNumbers(
            $item['imei_numbers'] ?? $item['imeis'] ?? $item['imei'] ?? $item['imei_1']
            ?? $product?->imei_numbers ?? $product?->imei ?? $product?->imei_1 ?? null
        );
    }

    private function normalizeImeiNumbers($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/[\r\n,]+/', $value);
            }
        }
        if (! is_array($value)) {
            $value = $value ? [$value] : [];
        }
        return collect($value)
            ->map(fn ($item) => is_array($item) ? ($item['imei_1'] ?? $item['imei'] ?? '') : $item)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function moveImei(User $actor, ImeiRegistry $imei, string $type, string $reference, string $toStatus, array $extra = []): ImeiMovement
    {
        return $this->createRecord(new ImeiMovement(), array_merge($extra, [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'product_id' => $extra['product_id'] ?? ($imei->product_id ?? null),
            'imei_uuid' => $imei->uuid,
            'product_uuid' => $extra['product_uuid'] ?? $imei->product_uuid,
            'movement_type' => $type,
            'reference' => $reference,
            'from_status' => $imei->status,
            'to_status' => $toStatus,
            'moved_at' => now(),
        ]));
    }

    private function inventory(User $actor, Product $product, string $type, int $quantity, string $reference, string $reason, array $item = []): void
    {
        $this->createRecord(new InventoryTransaction(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'product_name' => $product->product_name,
            'type' => $type,
            'quantity' => $quantity,
            'selected_unit' => $item['selected_unit'] ?? null,
            'selected_unit_label' => $item['selected_unit_label'] ?? null,
            'conversion_factor' => $item['conversion_factor'] ?? null,
            'reference' => $reference,
            'reason' => $reason,
            'transacted_at' => now(),
        ]);
    }

    private function cashbook(User $actor, string $type, string $description, float $debit, float $credit, string $reference): void
    {
        $this->createRecord(new CashbookEntry(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'type' => $type,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
            'reference' => $reference,
            'entry_at' => now(),
        ]);
    }

    private function customerLedger(User $actor, Customer $customer, string $type, float $amount, string $reference, ?string $dueDate): void
    {
        $this->createRecord(new CustomerLedger(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'customer_id' => $customer->id,
            'customer_uuid' => $customer->uuid,
            'type' => $type,
            'amount' => $amount,
            'reference' => $reference,
            'due_date' => $dueDate,
            'entry_at' => now(),
        ]);
        $customer->update(['balance' => (float) $customer->balance + $amount]);
    }

    private function supplierLedger(User $actor, Supplier $supplier, string $type, float $amount, string $reference): void
    {
        $this->createRecord(new SupplierLedger(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'supplier_id' => $supplier->id,
            'supplier_uuid' => $supplier->uuid,
            'type' => $type,
            'amount' => $amount,
            'reference' => $reference,
            'entry_at' => now(),
        ]);
        $supplier->update(['balance' => (float) $supplier->balance + $amount]);
    }

    private function customer(?string $uuid, User $actor): ?Customer
    {
        if (! $uuid) {
            return null;
        }
        return Customer::where('license_uuid', $actor->license_uuid)->where('uuid', $uuid)->first();
    }

    private function supplier(?string $uuid, User $actor): ?Supplier
    {
        if (! $uuid) {
            return null;
        }
        return Supplier::where('license_uuid', $actor->license_uuid)->where('uuid', $uuid)->first();
    }

    private function saleItem(?string $uuid, User $actor): ?SaleItem
    {
        if (! $uuid) {
            return null;
        }
        return SaleItem::where('license_uuid', $actor->license_uuid)->where('uuid', $uuid)->first();
    }

    private function purchaseItem(?string $uuid, User $actor): ?PurchaseItem
    {
        if (! $uuid) {
            return null;
        }
        return PurchaseItem::where('license_uuid', $actor->license_uuid)->where('uuid', $uuid)->first();
    }

    private function nextNumber(string $model, string $prefix, User $actor): string
    {
        $count = $model::withTrashed()->where('license_uuid', $actor->license_uuid)->count() + 1;
        return $prefix . '-' . str_pad((string) $count, 5, '0', STR_PAD_LEFT);
    }

    private function createRecord(Model $model, array $payload): Model
    {
        $table = $model->getTable();
        if (Schema::hasTable($table)) {
            $payload = array_intersect_key($payload, array_flip(Schema::getColumnListing($table)));
        }
        $this->validateLegacyPayload($table, $payload);

        try {
            return $model->newQuery()->create($payload);
        } catch (QueryException $exception) {
            Log::error('Mobile shop child insert failed', [
                'table' => $table,
                'payload' => $payload,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'database' => "Could not save {$table}. Please verify required legacy fields.",
            ]);
        }
    }

    private function validateLegacyPayload(string $table, array $payload): void
    {
        $required = [
            'sale_items' => ['sale_id', 'product_id'],
            'inventory_transactions' => ['product_id'],
            'purchase_items' => ['purchase_id', 'product_id'],
        ][$table] ?? [];

        foreach ($required as $column) {
            if ($this->hasColumn($table, $column) && empty($payload[$column])) {
                Log::error('Mobile shop child insert failed', [
                    'table' => $table,
                    'payload' => $payload,
                    'missing_column' => $column,
                ]);

                throw ValidationException::withMessages([
                    $column => "{$table}.{$column} is required by the legacy database schema.",
                ]);
            }
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    private function queueMany(User $actor, array $rows): void
    {
        foreach ($rows as [$entity, $action, $model]) {
            $payload = [
                'uuid' => $model->uuid,
                'device_id' => 'server',
                'entity' => $entity,
                'action' => $action,
                'payload' => $model->toArray(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'record_updated_at' => now(),
                'revision' => $model->revision ?? 1,
                'is_tombstone' => false,
                'synced_at' => now(),
            ];
            if (Schema::hasTable('sync_queue')) {
                $payload = array_intersect_key($payload, array_flip(Schema::getColumnListing('sync_queue')));
            }
            SyncQueue::create($payload);
        }
    }

    private function audit(User $actor, string $action, string $entity, string $uuid, array $payload): void
    {
        $this->createRecord(new AuditLog(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'user_name' => $actor->name,
            'action' => $action,
            'entity' => $entity,
            'entity_uuid' => $uuid,
            'details' => "{$action} {$entity}",
            'metadata' => $payload,
        ]);
    }

    private function assertMobileShop(User $actor): void
    {
        if (! $actor->license_uuid || ! in_array($this->retailBusinessTypeKey($actor->business_type), ['mobile_shop', 'general_store'], true)) {
            throw ValidationException::withMessages(['business_type' => 'Retail tenant is required.']);
        }
    }

    private function retailBusinessTypeKey(?string $type): string
    {
        $key = strtolower(str_replace([' ', '-'], '_', (string) $type));
        if (in_array($key, ['mobile_shop', 'mobile'], true)) {
            return 'mobile_shop';
        }
        if (in_array($key, ['general_store', 'grocery_store', 'shopping_mall', 'traders', 'retail_shop'], true)) {
            return 'general_store';
        }

        return $key;
    }

    private function isMobileShopTenant(User $actor): bool
    {
        return $this->retailBusinessTypeKey($actor->business_type) === 'mobile_shop';
    }
}
