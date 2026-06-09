<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\ImeiRegistry;
use App\Models\MasterCatalog;
use App\Models\Medicine;
use App\Models\Product;
use App\Models\SyncQueue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BarcodeRegistryService
{
    private array $productLookupFields = [
        'barcode',
        'secondary_barcode',
        'qr_code',
        'sku',
        'box_barcode',
        'carton_barcode',
    ];

    public function lookup(string $scan, User $actor): array
    {
        $this->assertTenant($actor);
        $term = $this->normalize($scan);
        if ($term === '') {
            throw ValidationException::withMessages(['scan' => 'Barcode, QR, SKU or product name is required.']);
        }

        if ($this->isMobileShop($actor) && Schema::hasTable('imei_registry')) {
            $imei = $this->findImei($term, $actor);
            if ($imei) {
                $product = $imei->product_uuid
                    ? Product::where('license_uuid', $actor->license_uuid)->where('uuid', $imei->product_uuid)->first()
                    : null;

                return [
                    'match_type' => 'imei',
                    'scan' => $scan,
                    'product' => $product,
                    'imei' => $imei,
                    'quantity_multiplier' => 1,
                ];
            }
        }

        foreach ($this->productLookupFields as $field) {
            $product = $this->findProductByField($field, $term, $actor);
            if ($product) {
                return [
                    'match_type' => $field,
                    'scan' => $scan,
                    'product' => $product,
                    'imei' => null,
                    'quantity_multiplier' => $this->quantityMultiplier($field, $product),
                ];
            }
        }

        $product = Product::where('license_uuid', $actor->license_uuid)
            ->where('product_name', 'like', "%{$term}%")
            ->orderBy('product_name')
            ->first();

        if ($product) {
            return [
                'match_type' => 'product_name',
                'scan' => $scan,
                'product' => $product,
                'imei' => null,
                'quantity_multiplier' => 1,
            ];
        }

        $medicine = $this->findMedicine($term, $actor);
        if ($medicine) {
            return [
                'match_type' => 'medicine_master',
                'scan' => $scan,
                'product' => null,
                'medicine' => $medicine,
                'imei' => null,
                'quantity_multiplier' => 1,
            ];
        }

        $catalog = $this->findCatalog($term, $actor);
        if ($catalog) {
            return [
                'match_type' => 'master_catalog',
                'scan' => $scan,
                'product' => null,
                'catalog' => $catalog,
                'imei' => null,
                'quantity_multiplier' => $this->quantityMultiplier('catalog', $catalog),
            ];
        }

        throw ValidationException::withMessages(['scan' => 'No product found for this scan.']);
    }

    public function receive(array $payload, User $actor): array
    {
        $this->assertTenant($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $quantity = max(1, (int) ($payload['quantity'] ?? 1));
            $scan = $payload['scan'] ?? $payload['barcode'] ?? null;
            $productUuid = $payload['product_uuid'] ?? null;

            $product = $productUuid
                ? Product::where('license_uuid', $actor->license_uuid)->where('uuid', $productUuid)->lockForUpdate()->first()
                : null;

            if (! $product && $scan) {
                $match = $this->lookup($scan, $actor);
                $product = $match['product'] ?? null;
                $quantity *= (int) ($match['quantity_multiplier'] ?? 1);
            }

            if (! $product) {
                throw ValidationException::withMessages(['product_uuid' => 'Product is required for inventory receiving.']);
            }

            $update = [
                'quantity' => (int) ($product->quantity ?? 0) + $quantity,
                'revision' => ((int) ($product->revision ?? 1)) + 1,
            ];

            foreach (['purchase_price', 'cost_price', 'batch_number', 'expiry_date'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $target = $field === 'cost_price' ? 'purchase_price' : $field;
                    if (Schema::hasColumn('products', $target)) {
                        $update[$target] = $payload[$field];
                    }
                }
            }

            $product->update($update);

            $transaction = InventoryTransaction::create([
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'product_id' => $product->id,
                'product_uuid' => $product->uuid,
                'product_name' => $product->product_name,
                'type' => 'Stock In',
                'quantity' => $quantity,
                'reference' => $payload['reference'] ?? $payload['invoice_number'] ?? 'BARCODE-RECEIVE',
                'reason' => $payload['reason'] ?? 'Barcode Receiving',
                'transacted_at' => now(),
            ]);

            $this->queue($actor, 'products', 'update', $product->fresh());
            $this->queue($actor, 'inventory_transactions', 'create', $transaction);

            return [
                'product' => $product->fresh(),
                'inventory_transaction' => $transaction->fresh(),
            ];
        }, 3);
    }

    public function validateProductPayload(array $payload, User $actor, ?string $ignoreUuid = null): void
    {
        if (! $actor->license_uuid) {
            return;
        }

        $values = collect($this->productLookupFields)
            ->filter(fn ($field) => ! empty($payload[$field]) && Schema::hasColumn('products', $field))
            ->mapWithKeys(fn ($field) => [$field => $this->normalize($payload[$field])])
            ->filter()
            ->all();

        foreach ($values as $field => $value) {
            $duplicate = Product::where('license_uuid', $actor->license_uuid)
                ->when($ignoreUuid, fn ($query) => $query->where('uuid', '!=', $ignoreUuid))
                ->where($field, $value)
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    $field => "Duplicate {$field} already assigned to {$duplicate->product_name}.",
                ]);
            }
        }
    }

    private function findProductByField(string $field, string $term, User $actor): ?Product
    {
        if (! Schema::hasColumn('products', $field)) {
            return null;
        }

        return Product::where('license_uuid', $actor->license_uuid)
            ->where($field, $term)
            ->first();
    }

    private function findImei(string $term, User $actor): ?ImeiRegistry
    {
        return ImeiRegistry::where('license_uuid', $actor->license_uuid)
            ->where(function (Builder $query) use ($term) {
                foreach (['imei_1', 'imei_2', 'serial_number'] as $field) {
                    if (Schema::hasColumn('imei_registry', $field)) {
                        $query->orWhere($field, $term);
                    }
                }
                if (Schema::hasColumn('imei_registry', 'imei_numbers')) {
                    $query->orWhereJsonContains('imei_numbers', $term);
                }
            })
            ->first();
    }

    private function findMedicine(string $term, User $actor): ?Medicine
    {
        if (! in_array($this->businessKey($actor), ['pharmacy', 'hospital'], true) || ! Schema::hasTable('medicines')) {
            return null;
        }

        return Medicine::query()
            ->where(function ($query) use ($term) {
                $query->where('barcode', $term)
                    ->orWhere('registration_no', $term)
                    ->orWhere('brand_name', 'like', "%{$term}%")
                    ->orWhere('generic_name', 'like', "%{$term}%");
            })
            ->first();
    }

    private function findCatalog(string $term, User $actor): ?MasterCatalog
    {
        if (! Schema::hasTable('master_catalogs')) {
            return null;
        }

        return MasterCatalog::where(function ($query) use ($actor) {
                $query->where('business_type', $actor->business_type)->orWhere('business_type', 'All');
            })
            ->where(function ($query) use ($term) {
                foreach (['barcode', 'secondary_barcode', 'qr_code', 'product_name', 'name'] as $field) {
                    if (! Schema::hasColumn('master_catalogs', $field)) {
                        continue;
                    }
                    str_contains($field, 'name')
                        ? $query->orWhere($field, 'like', "%{$term}%")
                        : $query->orWhere($field, $term);
                }
            })
            ->first();
    }

    private function quantityMultiplier(string $field, $row): int
    {
        if ($field === 'carton_barcode') {
            return max(1, (int) ($row->units_per_carton ?? $row->units_per_package ?? 1));
        }
        if ($field === 'box_barcode') {
            return max(1, (int) ($row->units_per_box ?? $row->units_per_package ?? 1));
        }

        return 1;
    }

    private function queue(User $actor, string $entity, string $action, $model): void
    {
        if (! Schema::hasTable('sync_queue')) {
            return;
        }

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

        SyncQueue::create(array_intersect_key($payload, array_flip(Schema::getColumnListing('sync_queue'))));
    }

    private function assertTenant(User $actor): void
    {
        if (! $actor->license_uuid) {
            throw ValidationException::withMessages(['license_uuid' => 'Tenant scope is required.']);
        }
    }

    private function normalize(mixed $value): string
    {
        return trim((string) $value);
    }

    private function isMobileShop(User $actor): bool
    {
        return $this->businessKey($actor) === 'mobile_shop';
    }

    private function businessKey(User $actor): string
    {
        return strtolower(str_replace([' ', '-'], '_', (string) $actor->business_type));
    }
}
