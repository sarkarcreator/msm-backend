<?php

namespace App\Services;

use App\Models\CashbookEntry;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Models\SupplierLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialReversalService
{
    public function reverseSale(Sale $sale, User $actor, string $reason = 'Sale Reversal'): array
    {
        $this->assertTenant($sale, $actor);

        return DB::transaction(function () use ($sale, $actor, $reason) {
            $sale = Sale::where('license_uuid', $actor->license_uuid)
                ->where('uuid', $sale->uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($sale->status, ['Reversed', 'Cancelled'], true)) {
                throw ValidationException::withMessages(['sale' => 'Sale is already reversed or cancelled.']);
            }

            $items = SaleItem::where('license_uuid', $actor->license_uuid)
                ->where(function ($query) use ($sale) {
                    if (Schema::hasColumn('sale_items', 'sale_uuid')) {
                        $query->where('sale_uuid', $sale->uuid);
                    }
                    if (Schema::hasColumn('sale_items', 'sale_id')) {
                        $query->orWhere('sale_id', $sale->id);
                    }
                })
                ->lockForUpdate()
                ->get();

            $inventory = [];
            foreach ($items as $item) {
                $product = Product::where('license_uuid', $actor->license_uuid)
                    ->where(function ($query) use ($item) {
                        if (Schema::hasColumn('sale_items', 'product_uuid') && ! empty($item->product_uuid)) {
                            $query->where('uuid', $item->product_uuid);
                        }
                        if (! empty($item->product_id)) {
                            $query->orWhere('id', $item->product_id);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    continue;
                }

                $quantity = max(1, (int) $item->quantity);
                $product->update([
                    'quantity' => (int) $product->quantity + $quantity,
                    'revision' => ((int) ($product->revision ?? 1)) + 1,
                ]);
                $inventory[] = $this->inventory($actor, $product, $quantity, $sale->invoice_number, $reason);
            }

            if (! empty($sale->customer_uuid) && (float) $sale->balance > 0) {
                $customer = Customer::where('license_uuid', $actor->license_uuid)->where('uuid', $sale->customer_uuid)->lockForUpdate()->first();
                if ($customer) {
                    $this->customerLedger($actor, $customer, 'Sale Reversal', -1 * (float) $sale->balance, $sale->invoice_number);
                }
            }

            if ((float) $sale->paid > 0) {
                $this->cashbook($actor, 'Sale Reversal', $sale->invoice_number, 0, (float) $sale->paid, $sale->uuid);
            }

            $sale->update([
                'status' => 'Reversed',
                'balance' => 0,
                'revision' => ((int) ($sale->revision ?? 1)) + 1,
            ]);

            return [
                'sale' => $sale->fresh(),
                'inventory_transactions' => collect($inventory)->map->fresh()->values(),
            ];
        }, 3);
    }

    public function reverseSupplierLedger(Supplier $supplier, User $actor, float $amount, string $reference, string $reason = 'Supplier Reversal'): SupplierLedger
    {
        $this->assertTenant($supplier, $actor);

        return DB::transaction(function () use ($supplier, $actor, $amount, $reference, $reason) {
            $supplier = Supplier::where('license_uuid', $actor->license_uuid)->where('uuid', $supplier->uuid)->lockForUpdate()->firstOrFail();
            $ledger = SupplierLedger::create([
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'supplier_id' => $supplier->id,
                'supplier_uuid' => $supplier->uuid,
                'type' => $reason,
                'amount' => -1 * abs($amount),
                'reference' => $reference,
                'entry_at' => now(),
            ]);
            $supplier->update(['balance' => max(0, (float) $supplier->balance - abs($amount))]);

            return $ledger->fresh();
        }, 3);
    }

    private function inventory(User $actor, Product $product, int $quantity, string $reference, string $reason): InventoryTransaction
    {
        return InventoryTransaction::create([
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'product_id' => $product->id,
            'product_uuid' => $product->uuid,
            'product_name' => $product->product_name,
            'type' => 'Stock In',
            'quantity' => $quantity,
            'reference' => $reference,
            'reason' => $reason,
            'transacted_at' => now(),
        ]);
    }

    private function customerLedger(User $actor, Customer $customer, string $type, float $amount, string $reference): void
    {
        CustomerLedger::create([
            'uuid' => (string) Str::uuid(),
            'license_uuid' => $actor->license_uuid,
            'business_type' => $actor->business_type,
            'customer_id' => $customer->id,
            'customer_uuid' => $customer->uuid,
            'type' => $type,
            'amount' => $amount,
            'reference' => $reference,
            'entry_at' => now(),
        ]);
        $customer->update(['balance' => max(0, (float) $customer->balance + $amount)]);
    }

    private function cashbook(User $actor, string $type, string $description, float $debit, float $credit, string $reference): void
    {
        CashbookEntry::create([
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

    private function assertTenant($record, User $actor): void
    {
        if (! $actor->license_uuid || ($record->license_uuid ?? null) !== $actor->license_uuid) {
            throw ValidationException::withMessages(['license_uuid' => 'This financial record does not belong to the active tenant.']);
        }
    }
}
