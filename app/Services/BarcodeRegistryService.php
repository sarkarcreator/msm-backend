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
        'product_code',
        'sku',
        'box_barcode',
        'carton_barcode',
    ];

    public function lookup(string $scan, User $actor, int $limit = 0): array
    {
        $this->assertTenant($actor);
        $term = $this->normalize($scan);
        if ($term === '') {
            throw ValidationException::withMessages(['scan' => 'Barcode, QR, SKU or product name is required.']);
        }

        foreach (['barcode', 'secondary_barcode', 'qr_code'] as $field) {
            $product = $this->findProductByField($field, $term, $actor);
            if ($product) {
                return $this->withResults([
                    'match_type' => $field,
                    'scan' => $scan,
                    'product' => $product,
                    'imei' => null,
                    'quantity_multiplier' => $this->quantityMultiplier($field, $product),
                ], $term, $actor, $limit);
            }
        }

        $packagingMatch = $this->findPackagingProduct($term, $actor);
        if ($packagingMatch) {
            return $this->withResults([
                'match_type' => 'packaging:'.$packagingMatch['unit']['key'],
                'scan' => $scan,
                'product' => $packagingMatch['product'],
                'imei' => null,
                'packaging_unit' => $packagingMatch['unit'],
                'quantity_multiplier' => $packagingMatch['unit']['factor'],
            ], $term, $actor, $limit);
        }

        if ($this->isMobileShop($actor) && Schema::hasTable('imei_registry')) {
            $imei = $this->findImei($term, $actor);
            if ($imei) {
                $product = $imei->product_uuid
                    ? Product::where('license_uuid', $actor->license_uuid)->where('uuid', $imei->product_uuid)->first()
                    : null;

                return $this->withResults([
                    'match_type' => 'imei',
                    'scan' => $scan,
                    'product' => $product,
                    'imei' => $imei,
                    'quantity_multiplier' => 1,
                ], $term, $actor, $limit);
            }
        }

        foreach (['sku', 'product_code', 'box_barcode', 'carton_barcode'] as $field) {
            $product = $this->findProductByField($field, $term, $actor);
            if ($product) {
                return $this->withResults([
                    'match_type' => $field,
                    'scan' => $scan,
                    'product' => $product,
                    'imei' => null,
                    'quantity_multiplier' => $this->quantityMultiplier($field, $product),
                ], $term, $actor, $limit);
            }
        }

        $product = $this->productTextMatches($term, $actor, 1)->first();

        if ($product) {
            return $this->withResults([
                'match_type' => stripos((string) $product->product_name, $term) !== false ? 'product_name' : 'brand',
                'scan' => $scan,
                'product' => $product,
                'imei' => null,
                'quantity_multiplier' => 1,
            ], $term, $actor, $limit);
        }

        $medicine = $this->findMedicine($term, $actor);
        if ($medicine) {
            return $this->withResults([
                'match_type' => 'medicine_master',
                'scan' => $scan,
                'product' => null,
                'medicine' => $medicine,
                'imei' => null,
                'quantity_multiplier' => 1,
            ], $term, $actor, $limit);
        }

        $catalog = $this->findCatalog($term, $actor);
        if ($catalog) {
            return $this->withResults([
                'match_type' => 'master_catalog',
                'scan' => $scan,
                'product' => null,
                'catalog' => $catalog,
                'imei' => null,
                'quantity_multiplier' => $this->quantityMultiplier('catalog', $catalog),
            ], $term, $actor, $limit);
        }

        throw ValidationException::withMessages(['scan' => 'No product found for this scan.']);
    }

    public function suggestions(string $scan, User $actor, int $limit = 8): array
    {
        $this->assertTenant($actor);
        $term = $this->normalize($scan);
        if ($term === '') {
            return [];
        }

        $seen = [];
        $results = [];
        foreach ($this->exactProductMatches($term, $actor) as $field => $product) {
            $this->pushResult($results, $seen, $field, $product, null, $this->quantityMultiplier($field, $product));
        }

        foreach ($this->exactPackagingMatches($term, $actor) as $match) {
            $this->pushResult($results, $seen, 'packaging:'.$match['unit']['key'], $match['product'], null, $match['unit']['factor'], $match['unit']);
        }

        if ($this->isMobileShop($actor) && Schema::hasTable('imei_registry')) {
            $imei = $this->findImei($term, $actor);
            if ($imei) {
                $product = $imei->product_uuid
                    ? Product::where('license_uuid', $actor->license_uuid)->where('uuid', $imei->product_uuid)->first()
                    : null;
                $this->pushResult($results, $seen, 'imei', $product, $imei, 1);
            }
        }

        foreach ($this->productTextMatches($term, $actor, $limit * 2) as $product) {
            $match = stripos((string) $product->product_name, $term) !== false ? 'product_name' : 'brand';
            $this->pushResult($results, $seen, $match, $product, null, 1);
            if (count($results) >= $limit) {
                break;
            }
        }

        return array_slice($results, 0, $limit);
    }

    public function receive(array $payload, User $actor): array
    {
        $this->assertTenant($actor);

        return DB::transaction(function () use ($payload, $actor) {
            $quantity = max(1, (int) ($payload['quantity'] ?? 1));
            $scan = $payload['scan'] ?? $payload['barcode'] ?? null;
            $productUuid = $payload['product_uuid'] ?? null;
            $matchedUnit = null;

            $product = $productUuid
                ? Product::where('license_uuid', $actor->license_uuid)->where('uuid', $productUuid)->lockForUpdate()->first()
                : null;

            if (! $product && $scan) {
                $match = $this->lookup($scan, $actor);
                $product = $match['product'] ?? null;
                $matchedUnit = $match['packaging_unit'] ?? null;
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

            $transactionPayload = [
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $actor->license_uuid,
                'business_type' => $actor->business_type,
                'product_id' => $product->id,
                'product_uuid' => $product->uuid,
                'product_name' => $product->product_name,
                'type' => 'Stock In',
                'quantity' => $quantity,
                'selected_unit' => $matchedUnit['unit'] ?? $payload['selected_unit'] ?? null,
                'selected_unit_label' => $matchedUnit['label'] ?? $payload['selected_unit_label'] ?? null,
                'conversion_factor' => $matchedUnit['factor'] ?? $payload['conversion_factor'] ?? null,
                'unit_barcode' => $matchedUnit['barcode'] ?? $payload['unit_barcode'] ?? null,
                'reference' => $payload['reference'] ?? $payload['invoice_number'] ?? 'BARCODE-RECEIVE',
                'reason' => $payload['reason'] ?? 'Barcode Receiving',
                'transacted_at' => now(),
            ];
            $transaction = InventoryTransaction::create(array_intersect_key($transactionPayload, array_flip(Schema::getColumnListing('inventory_transactions'))));

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
                \Log::warning('Product duplicate identity blocked', [
                    'license_uuid' => $actor->license_uuid,
                    'field' => $field,
                    'value' => $value,
                    'existing_product_uuid' => $duplicate->uuid,
                    'incoming_product_uuid' => $ignoreUuid,
                ]);
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

    private function exactProductMatches(string $term, User $actor): array
    {
        $matches = [];
        foreach (['barcode', 'secondary_barcode', 'qr_code', 'sku', 'product_code', 'box_barcode', 'carton_barcode'] as $field) {
            $product = $this->findProductByField($field, $term, $actor);
            if ($product) {
                $matches[$field] = $product;
            }
        }
        return $matches;
    }

    private function withResults(array $payload, string $term, User $actor, int $limit): array
    {
        if ($limit > 0) {
            $payload['results'] = $this->suggestions($term, $actor, $limit);
        }
        return $payload;
    }

    private function pushResult(array &$results, array &$seen, string $matchType, ?Product $product, ?ImeiRegistry $imei, int $quantityMultiplier, ?array $packagingUnit = null): void
    {
        if (! $product?->uuid || isset($seen[$product->uuid.':'.$matchType])) {
            return;
        }
        $seen[$product->uuid.':'.$matchType] = true;
        $payload = [
            'match_type' => $matchType,
            'product' => $product,
            'imei' => $imei,
            'quantity_multiplier' => $quantityMultiplier,
        ];
        if ($packagingUnit) {
            $payload['packaging_unit'] = $packagingUnit;
        }
        $results[] = $payload;
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
                foreach (['barcode', 'secondary_barcode', 'qr_code', 'product_code', 'brand', 'product_name', 'name'] as $field) {
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

    private function productTextMatches(string $term, User $actor, int $limit)
    {
        $tokens = collect(preg_split('/\s+/', $term) ?: [])
            ->map(fn ($token) => trim($token))
            ->filter()
            ->values();
        $fields = collect(['product_name', 'brand', 'category', 'sku', 'product_code', 'pack_size', 'unit', 'variant_type'])
            ->filter(fn ($field) => Schema::hasColumn('products', $field))
            ->values()
            ->all();

        $query = Product::where('license_uuid', $actor->license_uuid);
        foreach ($tokens as $token) {
            $query->where(function ($builder) use ($fields, $token) {
                foreach ($fields as $field) {
                    $builder->orWhere($field, 'like', "%{$token}%");
                }
            });
        }

        return $query
            ->orderByRaw("CASE WHEN product_name = ? THEN 0 WHEN product_name LIKE ? THEN 1 ELSE 2 END", [$term, "{$term}%"])
            ->orderBy('product_name')
            ->limit(max(1, $limit))
            ->get();
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

    private function findPackagingProduct(string $term, User $actor): ?array
    {
        foreach ($this->exactPackagingMatches($term, $actor) as $match) {
            return $match;
        }
        return null;
    }

    private function exactPackagingMatches(string $term, User $actor): array
    {
        if (! Schema::hasColumn('products', 'packaging_units')) {
            return [];
        }

        $matches = [];
        Product::where('license_uuid', $actor->license_uuid)
            ->whereNotNull('packaging_units')
            ->chunkById(200, function ($products) use ($term, &$matches) {
                foreach ($products as $product) {
                    foreach ($this->packagingUnits($product) as $unit) {
                        if (($unit['barcode'] ?? '') !== '' && $this->normalize($unit['barcode']) === $term) {
                            $matches[] = ['product' => $product, 'unit' => $unit];
                        }
                    }
                }
            });

        return $matches;
    }

    private function packagingUnits(Product $product): array
    {
        $baseUnit = in_array((string) ($product->unit ?? ''), ['', 'Single Unit', 'Unit'], true)
            ? (string) ($product->variant_type ?? 'Piece')
            : (string) $product->unit;
        $raw = $product->packaging_units;
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (isset($decoded['units']) && is_array($decoded['units'])) {
            $decoded = $decoded['units'];
        } elseif (isset($decoded['levels']) && is_array($decoded['levels'])) {
            $decoded = $decoded['levels'];
        } elseif (isset($decoded['packaging_units']) && is_array($decoded['packaging_units'])) {
            $decoded = $decoded['packaging_units'];
        }
        $rows = is_array($decoded) ? array_values($decoded) : [];
        $indexed = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = Str::of((string) ($row['key'] ?? $row['unit'] ?? $row['unit_name'] ?? $row['label'] ?? 'level_'.($index + 1)))->lower()->replace(' ', '_')->toString();
            $indexed[$key] = array_merge($row, [
                'key' => $key,
                'parent_key' => Str::of((string) ($row['parent_key'] ?? $row['parent'] ?? $row['contains_unit'] ?? 'base'))->lower()->replace(' ', '_')->toString(),
                'conversion_quantity' => max(1, (int) ($row['conversion_quantity'] ?? $row['contains_quantity'] ?? $row['contains'] ?? $row['qty'] ?? $row['factor'] ?? $row['conversion_factor'] ?? $row['stock_factor'] ?? 1)),
            ]);
        }

        $factorFor = function (array $row, array $seen = []) use (&$factorFor, &$indexed): int {
            if (($row['key'] ?? 'base') === 'base' || in_array($row['key'], $seen, true)) {
                return 1;
            }
            $seen[] = $row['key'];
            $parent = ($row['parent_key'] ?? 'base') === 'base' ? null : ($indexed[$row['parent_key']] ?? null);
            $parentFactor = $parent ? $factorFor($parent, $seen) : 1;
            return max(1, (int) ($row['conversion_quantity'] ?? 1)) * $parentFactor;
        };

        return array_map(function (array $row) use ($factorFor, $baseUnit) {
            $factor = $factorFor($row);
            return [
                'key' => $row['key'],
                'unit' => $row['unit'] ?? $row['unit_name'] ?? $row['label'] ?? $row['key'],
                'label' => $row['label'] ?? (($row['unit'] ?? $row['unit_name'] ?? $row['key'])." ({$factor} ".$this->pluralUnit($baseUnit).')'),
                'barcode' => $row['barcode'] ?? $row['secondary_barcode'] ?? $row['qr_code'] ?? $row['code'] ?? '',
                'factor' => $factor,
                'sale_price' => (float) ($row['sale_price'] ?? $row['unit_sale_price'] ?? $row['price'] ?? $row['default_price'] ?? (($product->sale_price ?? 0) * $factor)),
                'purchase_price' => (float) ($row['purchase_price'] ?? $row['unit_cost_price'] ?? $row['cost_price'] ?? $row['default_cost'] ?? (($product->purchase_price ?? 0) * $factor)),
            ];
        }, array_values($indexed));
    }

    private function pluralUnit(string $unit): string
    {
        $text = trim(preg_replace('/\s*\(.*/', '', $unit) ?: 'Piece');
        return str_ends_with(strtolower($text), 's') ? $text : $text.'s';
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
