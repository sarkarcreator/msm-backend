<?php

namespace App\Services;

use App\Models\CashbookEntry;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SyncQueue;
use App\Models\TraderDeliveryChallan;
use App\Models\TraderDistributorLedger;
use App\Models\TraderRecovery;
use App\Models\TraderRetailer;
use App\Models\TraderSalesman;
use App\Models\TraderSalesmanLedger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TradersEnterpriseService
{
    public function createSale(array $payload, User $actor): array
    {
        $this->assertTraders($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $items = collect($payload['cart'] ?? $payload['items'] ?? []);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Sale cart is empty.']);
            }

            $retailer = $this->retailer($payload['retailer_uuid'] ?? $payload['customer_uuid'] ?? null, $actor);
            $salesman = $this->salesman($payload['salesman_uuid'] ?? null, $actor);
            $products = Product::where('license_uuid', $actor->license_uuid)
                ->whereIn('uuid', $items->pluck('product_uuid')->filter()->values())
                ->lockForUpdate()
                ->get()
                ->keyBy('uuid');

            $subtotal = 0;
            $profit = 0;
            $lines = [];
            foreach ($items as $item) {
                $product = $products->get($item['product_uuid'] ?? '');
                if (! $product) {
                    throw ValidationException::withMessages(['product_uuid' => 'Product not found in this traders inventory.']);
                }
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                if ((int) $product->quantity < $quantity) {
                    throw ValidationException::withMessages(['stock' => "{$product->product_name} stock is not enough."]);
                }
                $price = (float) ($item['price'] ?? $product->sale_price ?? 0);
                $subtotal += $quantity * $price;
                $profit += ($price - (float) ($product->purchase_price ?? 0)) * $quantity;
                $lines[] = compact('product', 'item', 'quantity', 'price');
            }

            $discount = (float) ($payload['discount'] ?? 0);
            $tax = (float) ($payload['tax'] ?? 0);
            $total = $subtotal - $discount + $tax;
            $paid = (float) ($payload['paid'] ?? 0);
            if (($payload['payment_type'] ?? 'cash') !== 'credit' && $paid <= 0) {
                $paid = $total;
            }
            $balance = max(0, $total - $paid);
            $commission = $this->commission($salesman, $total);
            $now = now();

            $sale = $this->createRecord(new Sale(), [
                'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'invoice_number' => $payload['invoice_number'] ?? $this->nextNumber(Sale::class, 'TR-INV', $actor),
                'customer_uuid' => $retailer?->uuid,
                'customer_name' => $retailer?->shop_name ?? ($payload['retailer_name'] ?? 'Walk-in Retailer'),
                'retailer_uuid' => $retailer?->uuid,
                'retailer_name' => $retailer?->shop_name,
                'salesman_uuid' => $salesman?->uuid ?? ($payload['salesman_uuid'] ?? null),
                'salesman_name' => $salesman?->name ?? ($payload['salesman_name'] ?? null),
                'territory_uuid' => $retailer?->territory_uuid ?? ($payload['territory_uuid'] ?? null),
                'territory_name' => $retailer?->territory_name ?? ($payload['territory_name'] ?? null),
                'route_uuid' => $retailer?->route_uuid ?? ($payload['route_uuid'] ?? null),
                'route_name' => $retailer?->route_name ?? ($payload['route_name'] ?? null),
                'challan_uuid' => $payload['challan_uuid'] ?? null,
                'vehicle_number' => $payload['vehicle_number'] ?? null,
                'payment_type' => $payload['payment_type'] ?? 'cash',
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'paid' => $paid,
                'balance' => $balance,
                'profit' => $profit - $discount,
                'commission_amount' => $commission,
                'due_date' => $payload['due_date'] ?? null,
                'sold_at' => $payload['sold_at'] ?? $now,
                'status' => $balance > 0 ? 'Credit Due' : 'Paid',
                'revision' => 1,
            ]);

            $saleItems = [];
            $inventoryTransactions = [];
            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $product->update([
                    'quantity' => max(0, (int) $product->quantity - $line['quantity']),
                    'revision' => ((int) ($product->revision ?? 1)) + 1,
                ]);
                $saleItems[] = $this->createRecord(new SaleItem(), [
                    'uuid' => (string) Str::uuid(),
                    'license_uuid' => $actor->license_uuid,
                    'business_type' => $actor->business_type,
                    'sale_id' => $sale->id,
                    'sale_uuid' => $sale->uuid,
                    'product_id' => $product->id,
                    'product_uuid' => $product->uuid,
                    'product_name' => $product->product_name,
                    'brand' => $product->brand,
                    'company_name' => $product->company_name ?? null,
                    'quantity' => $line['quantity'],
                    'price' => $line['price'],
                    'total' => $line['quantity'] * $line['price'],
                    'profit' => ($line['price'] - (float) ($product->purchase_price ?? 0)) * $line['quantity'],
                ]);
                $inventoryTransactions[] = $this->inventory($actor, $product, 'Stock Out', -$line['quantity'], $sale->invoice_number, 'Traders Sale');
            }

            $salesmanLedger = $salesman ? $this->salesmanLedger($actor, $salesman, 'Sale', $total, 0, $commission, $sale->invoice_number) : null;
            $distributorLedger = $this->distributorLedger($actor, 'Sale', $sale->invoice_number, $paid, $balance, $sale->invoice_number);
            $cashbook = $this->cashbook($actor, 'Traders Sale', $sale->invoice_number, $paid, 0, $sale->uuid);
            if ($retailer && $balance > 0) {
                $retailer->update(['balance' => (float) $retailer->balance + $balance]);
            }

            $this->queueMany($actor, [
                ['sales', 'create', $sale],
                ...collect($saleItems)->map(fn ($item) => ['sale_items', 'create', $item])->all(),
                ...collect($lines)->map(fn ($line) => ['products', 'update', $line['product']])->all(),
                ...collect($inventoryTransactions)->map(fn ($transaction) => ['inventory_transactions', 'create', $transaction])->all(),
                ...array_filter([
                    ['cashbook', 'create', $cashbook],
                    $salesmanLedger ? ['trader_salesman_ledgers', 'create', $salesmanLedger] : null,
                    ['trader_distributor_ledgers', 'create', $distributorLedger],
                ]),
            ]);

            return [
                'sale' => $sale->fresh(),
                'sale_items' => collect($saleItems)->map->fresh()->values(),
                'products' => collect($lines)->map(fn ($line) => $line['product']->fresh())->values(),
                'inventory_transactions' => collect($inventoryTransactions)->map->fresh()->values(),
                'cashbook' => [$cashbook->fresh()],
                'retailers' => $retailer ? [$retailer->fresh()] : [],
                'salesman_ledgers' => $salesmanLedger ? [$salesmanLedger->fresh()] : [],
                'distributor_ledgers' => [$distributorLedger->fresh()],
            ];
        }, 3);
    }

    public function createRecovery(array $payload, User $actor): array
    {
        $this->assertTraders($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $retailer = $this->retailer($payload['retailer_uuid'] ?? null, $actor);
            if (! $retailer) {
                throw ValidationException::withMessages(['retailer_uuid' => 'Retailer is required.']);
            }
            $salesman = $this->salesman($payload['salesman_uuid'] ?? null, $actor);
            $amount = (float) ($payload['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Recovery amount must be greater than zero.']);
            }

            $recovery = $this->createRecord(new TraderRecovery(), [
                'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
                'recovery_uuid' => $payload['recovery_uuid'] ?? (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'retailer_uuid' => $retailer->uuid,
                'retailer_name' => $retailer->shop_name,
                'salesman_uuid' => $salesman?->uuid,
                'salesman_name' => $salesman?->name ?? ($payload['salesman_name'] ?? null),
                'amount' => $amount,
                'payment_method' => $payload['payment_method'] ?? 'Cash',
                'date' => $payload['date'] ?? now()->toDateString(),
                'notes' => $payload['notes'] ?? null,
                'revision' => 1,
            ]);
            $retailer->update(['balance' => max(0, (float) $retailer->balance - $amount)]);
            $salesmanLedger = $salesman ? $this->salesmanLedger($actor, $salesman, 'Recovery', 0, $amount, 0, $recovery->uuid) : null;
            $distributorLedger = $this->distributorLedger($actor, 'Recovery', $retailer->shop_name, $amount, 0, $recovery->uuid);
            $cashbook = $this->cashbook($actor, 'Recovery', $retailer->shop_name, $amount, 0, $recovery->uuid);

            $this->queueMany($actor, [
                ['trader_recoveries', 'create', $recovery],
                ['trader_retailers', 'update', $retailer],
                ['cashbook', 'create', $cashbook],
                ...array_filter([
                    $salesmanLedger ? ['trader_salesman_ledgers', 'create', $salesmanLedger] : null,
                    ['trader_distributor_ledgers', 'create', $distributorLedger],
                ]),
            ]);

            return [
                'recovery' => $recovery->fresh(),
                'retailers' => [$retailer->fresh()],
                'cashbook' => [$cashbook->fresh()],
                'salesman_ledgers' => $salesmanLedger ? [$salesmanLedger->fresh()] : [],
                'distributor_ledgers' => [$distributorLedger->fresh()],
            ];
        }, 3);
    }

    public function createDeliveryChallan(array $payload, User $actor): array
    {
        $this->assertTraders($actor);
        return DB::transaction(function () use ($payload, $actor) {
            $challan = $this->createRecord(new TraderDeliveryChallan(), [
                'uuid' => $payload['uuid'] ?? (string) Str::uuid(),
                'challan_uuid' => $payload['challan_uuid'] ?? (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'challan_number' => $payload['challan_number'] ?? $this->nextNumber(TraderDeliveryChallan::class, 'CH', $actor),
                'retailer_uuid' => $payload['retailer_uuid'] ?? null,
                'retailer_name' => $payload['retailer_name'] ?? null,
                'salesman_uuid' => $payload['salesman_uuid'] ?? null,
                'salesman_name' => $payload['salesman_name'] ?? null,
                'vehicle_number' => $payload['vehicle_number'] ?? null,
                'date' => $payload['date'] ?? now()->toDateString(),
                'status' => $payload['status'] ?? 'Draft',
                'revision' => 1,
            ]);

            $this->queueMany($actor, [
                ['trader_delivery_challans', 'create', $challan],
            ]);

            return ['challan' => $challan->fresh()];
        }, 3);
    }

    private function retailer(?string $uuid, User $actor): ?TraderRetailer
    {
        return $uuid ? TraderRetailer::where('license_uuid', $actor->license_uuid)->where('uuid', $uuid)->first() : null;
    }

    private function salesman(?string $uuid, User $actor): ?TraderSalesman
    {
        return $uuid ? TraderSalesman::where('license_uuid', $actor->license_uuid)->where('uuid', $uuid)->first() : null;
    }

    private function commission(?TraderSalesman $salesman, float $total): float
    {
        if (! $salesman) {
            return 0;
        }
        return $salesman->commission_type === 'Fixed'
            ? (float) $salesman->commission_value
            : round($total * ((float) $salesman->commission_value / 100), 2);
    }

    private function nextNumber(string $model, string $prefix, User $actor): string
    {
        return $prefix . '-' . str_pad((string) ($model::withTrashed()->where('license_uuid', $actor->license_uuid)->count() + 1), 5, '0', STR_PAD_LEFT);
    }

    private function createRecord(Model $model, array $payload): Model
    {
        $table = $model->getTable();
        if (Schema::hasTable($table)) {
            $payload = array_intersect_key($payload, array_flip(Schema::getColumnListing($table)));
        }
        return $model->newQuery()->create($payload);
    }

    private function inventory(User $actor, Product $product, string $type, int $quantity, string $reference, string $reason): InventoryTransaction
    {
        return $this->createRecord(new InventoryTransaction(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'product_name' => $product->product_name,
            'type' => $type,
            'quantity' => $quantity,
            'reference' => $reference,
            'reason' => $reason,
            'transacted_at' => now(),
        ]);
    }

    private function cashbook(User $actor, string $type, string $description, float $debit, float $credit, string $reference): CashbookEntry
    {
        return $this->createRecord(new CashbookEntry(), [
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

    private function salesmanLedger(User $actor, TraderSalesman $salesman, string $type, float $debit, float $credit, float $commission, string $reference): TraderSalesmanLedger
    {
        return $this->createRecord(new TraderSalesmanLedger(), [
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'salesman_uuid' => $salesman->uuid,
            'salesman_name' => $salesman->name,
            'type' => $type,
            'debit' => $debit,
            'credit' => $credit,
            'commission' => $commission,
            'reference' => $reference,
            'entry_at' => now(),
        ]);
    }

    private function distributorLedger(User $actor, string $type, string $description, float $debit, float $credit, string $reference): TraderDistributorLedger
    {
        return $this->createRecord(new TraderDistributorLedger(), [
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

    private function assertTraders(User $actor): void
    {
        $type = strtolower(str_replace([' ', '-'], '_', (string) $actor->business_type));
        if (! $actor->license_uuid || $type !== 'traders') {
            throw ValidationException::withMessages(['business_type' => 'Traders tenant is required.']);
        }
    }
}
