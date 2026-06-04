<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->ensureProductColumns();
        $this->ensureSalePurchaseColumns();
        $this->createImeiRegistry();
        $this->createImeiMovements();
        $this->createWarrantyClaims();
        $this->createSaleReturns();
        $this->createPurchaseReturns();
        $this->ensureRepairColumns();
        $this->safeIndex('products', ['license_uuid', 'barcode'], 'products_license_barcode_index');
        $this->safeIndex('products', ['license_uuid', 'sku'], 'products_license_sku_index');
        $this->safeIndex('sales', ['license_uuid'], 'sales_license_index');
        $this->safeIndex('purchases', ['license_uuid'], 'purchases_license_index');
        $this->safeIndex('repairs', ['license_uuid'], 'repairs_license_index');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('warranty_claims');
        Schema::dropIfExists('imei_movements');
        Schema::dropIfExists('imei_registry');
    }

    private function ensureProductColumns(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['imei_1', 'imei_2', 'serial_number'] as $column) {
                if (! Schema::hasColumn('products', $column)) {
                    $table->string($column)->nullable()->index();
                }
            }
            if (! Schema::hasColumn('products', 'imei_numbers')) {
                $table->json('imei_numbers')->nullable();
            }
        });
    }

    private function ensureSalePurchaseColumns(): void
    {
        foreach (['sale_items', 'purchase_items'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['imei_uuid', 'imei_1', 'imei_2', 'serial_number'] as $column) {
                    if (! Schema::hasColumn($tableName, $column)) {
                        $column === 'imei_uuid'
                            ? $table->uuid($column)->nullable()->index()
                            : $table->string($column)->nullable()->index();
                    }
                }
                if (! Schema::hasColumn($tableName, 'imei_numbers')) {
                    $table->json('imei_numbers')->nullable();
                }
            });
        }
    }

    private function createImeiRegistry(): void
    {
        if (Schema::hasTable('imei_registry')) {
            return;
        }

        Schema::create('imei_registry', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->index();
            $table->string('business_type')->default('Mobile Shop')->index();
            $table->uuid('product_uuid')->nullable()->index();
            $table->string('product_name')->nullable()->index();
            $table->string('imei_1')->nullable();
            $table->string('imei_2')->nullable();
            $table->json('imei_numbers')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('customer_name')->nullable()->index();
            $table->string('invoice_number')->nullable()->index();
            $table->string('status')->default('In Stock')->index();
            $table->unique(['license_uuid', 'imei_1'], 'imei_registry_license_imei1_unique');
            $table->unique(['license_uuid', 'imei_2'], 'imei_registry_license_imei2_unique');
            $table->index(['license_uuid', 'serial_number'], 'imei_registry_license_serial_index');
        });
    }

    private function createImeiMovements(): void
    {
        if (Schema::hasTable('imei_movements')) {
            return;
        }

        Schema::create('imei_movements', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->index();
            $table->string('business_type')->default('Mobile Shop')->index();
            $table->uuid('imei_uuid')->nullable()->index();
            $table->uuid('product_uuid')->nullable()->index();
            $table->uuid('sale_uuid')->nullable()->index();
            $table->uuid('purchase_uuid')->nullable()->index();
            $table->uuid('repair_uuid')->nullable()->index();
            $table->uuid('warranty_claim_uuid')->nullable()->index();
            $table->string('movement_type')->index();
            $table->string('reference')->nullable()->index();
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('notes')->nullable();
            $table->timestamp('moved_at')->nullable()->index();
        });
    }

    private function createWarrantyClaims(): void
    {
        if (Schema::hasTable('warranty_claims')) {
            Schema::table('warranty_claims', function (Blueprint $table) {
                if (! Schema::hasColumn('warranty_claims', 'claim_number')) {
                    $table->string('claim_number')->nullable()->unique()->after('imei_uuid');
                }
            });
            return;
        }

        Schema::create('warranty_claims', function (Blueprint $table) {
            $this->base($table);
            $table->uuid('license_uuid')->index();
            $table->string('business_type')->default('Mobile Shop')->index();
            $table->uuid('product_uuid')->nullable()->index();
            $table->uuid('sale_uuid')->nullable()->index();
            $table->uuid('customer_uuid')->nullable()->index();
            $table->uuid('imei_uuid')->nullable()->index();
            $table->string('claim_number')->nullable()->unique();
            $table->date('claim_date')->nullable()->index();
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable()->index();
            $table->text('issue')->nullable();
            $table->text('resolution')->nullable();
            $table->string('status')->default('Open')->index();
        });
    }

    private function createSaleReturns(): void
    {
        if (! Schema::hasTable('sale_returns')) {
            Schema::create('sale_returns', function (Blueprint $table) {
                $this->base($table);
                $table->uuid('license_uuid')->index();
                $table->string('business_type')->default('Mobile Shop')->index();
                $table->uuid('sale_uuid')->nullable()->index();
                $table->uuid('customer_uuid')->nullable()->index();
                $table->string('return_number')->unique();
                $table->string('invoice_number')->nullable()->index();
                $table->string('return_type')->default('Refund');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('refund_amount', 14, 2)->default(0);
                $table->string('status')->default('Completed')->index();
                $table->timestamp('returned_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('sale_return_items')) {
            Schema::create('sale_return_items', function (Blueprint $table) {
                $this->base($table);
                $table->uuid('license_uuid')->index();
                $table->string('business_type')->default('Mobile Shop')->index();
                $table->uuid('sale_return_uuid')->index();
                $table->uuid('sale_item_uuid')->nullable()->index();
                $table->uuid('product_uuid')->nullable()->index();
                $table->uuid('imei_uuid')->nullable()->index();
                $table->string('product_name')->nullable();
                $table->string('imei_1')->nullable()->index();
                $table->integer('quantity')->default(1);
                $table->decimal('amount', 14, 2)->default(0);
            });
        }
    }

    private function createPurchaseReturns(): void
    {
        if (! Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table) {
                $this->base($table);
                $table->uuid('license_uuid')->index();
                $table->string('business_type')->default('Mobile Shop')->index();
                $table->uuid('purchase_uuid')->nullable()->index();
                $table->uuid('supplier_uuid')->nullable()->index();
                $table->string('return_number')->unique();
                $table->string('invoice_number')->nullable()->index();
                $table->decimal('total', 14, 2)->default(0);
                $table->string('status')->default('Completed')->index();
                $table->timestamp('returned_at')->nullable()->index();
            });
        }

        if (! Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table) {
                $this->base($table);
                $table->uuid('license_uuid')->index();
                $table->string('business_type')->default('Mobile Shop')->index();
                $table->uuid('purchase_return_uuid')->index();
                $table->uuid('purchase_item_uuid')->nullable()->index();
                $table->uuid('product_uuid')->nullable()->index();
                $table->uuid('imei_uuid')->nullable()->index();
                $table->string('product_name')->nullable();
                $table->integer('quantity')->default(1);
                $table->decimal('amount', 14, 2)->default(0);
            });
        }
    }

    private function ensureRepairColumns(): void
    {
        if (! Schema::hasTable('repairs')) {
            return;
        }

        Schema::table('repairs', function (Blueprint $table) {
            foreach ([
                'imei_uuid' => 'uuid',
                'technician_uuid' => 'uuid',
                'attachments' => 'json',
                'delivered_at' => 'timestamp',
            ] as $column => $type) {
                if (Schema::hasColumn('repairs', $column)) {
                    continue;
                }
                match ($type) {
                    'uuid' => $table->uuid($column)->nullable()->index(),
                    'json' => $table->json($column)->nullable(),
                    'timestamp' => $table->timestamp($column)->nullable()->index(),
                    default => $table->string($column)->nullable(),
                };
            }
        });
    }

    private function base(Blueprint $table): void
    {
        $table->id();
        $table->uuid('uuid')->unique();
        $table->json('metadata')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function safeIndex(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName) || $this->hasIndex($tableName, $name)) {
            return;
        }

        $existingColumns = collect($columns)->every(fn ($column) => Schema::hasColumn($tableName, $column));
        if (! $existingColumns) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($item) => ($item['name'] ?? '') === $index);
    }
};
