<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->ensureLedger('customer_ledgers', 'customer_uuid');
        $this->ensureLedger('supplier_ledgers', 'supplier_uuid');

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $this->base($table);
                $table->string('party_type')->nullable();
                $table->uuid('customer_uuid')->nullable()->index();
                $table->uuid('supplier_uuid')->nullable()->index();
                $table->string('party_name')->nullable();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('method')->nullable();
                $table->string('notes')->nullable();
                $table->timestamp('paid_at')->nullable();
            });
        }

        if (! Schema::hasTable('repairs')) {
            Schema::create('repairs', function (Blueprint $table) {
                $this->base($table);
                $table->string('job_number')->nullable();
                $table->uuid('customer_uuid')->nullable()->index();
                $table->string('customer_name')->nullable();
                $table->string('device_name')->nullable();
                $table->string('imei')->nullable();
                $table->string('problem')->nullable();
                $table->string('technician')->nullable();
                $table->decimal('charges', 14, 2)->default(0);
                $table->string('status')->default('Received');
            });
        }

        if (! Schema::hasTable('repair_updates')) {
            Schema::create('repair_updates', function (Blueprint $table) {
                $this->base($table);
                $table->uuid('repair_uuid')->index();
                $table->string('job_number')->nullable();
                $table->string('status')->nullable();
                $table->string('notes')->nullable();
            });
        }

        $this->addColumnIfMissing('products', 'imei');
        $this->addColumnIfMissing('products', 'supplier_name');
        $this->addColumnIfMissing('sales', 'customer_uuid', 'uuid');
        $this->addColumnIfMissing('sales', 'customer_name');
        $this->addColumnIfMissing('sales', 'balance', 'decimal');
        $this->addColumnIfMissing('sales', 'due_date', 'date');
        $this->addColumnIfMissing('purchases', 'supplier_uuid', 'uuid');
        $this->addColumnIfMissing('purchases', 'supplier_name');
        $this->addColumnIfMissing('purchases', 'paid', 'decimal');
        $this->addColumnIfMissing('purchases', 'balance', 'decimal');
        $this->addColumnIfMissing('sale_items', 'sale_uuid', 'uuid');
        $this->addColumnIfMissing('sale_items', 'product_uuid', 'uuid');
        $this->addColumnIfMissing('sale_items', 'product_name');
        $this->addColumnIfMissing('purchase_items', 'purchase_uuid', 'uuid');
        $this->addColumnIfMissing('purchase_items', 'product_uuid', 'uuid');
        $this->addColumnIfMissing('purchase_items', 'product_name');
        $this->addColumnIfMissing('purchase_items', 'cost_price', 'decimal');
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_updates');
        Schema::dropIfExists('repairs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('supplier_ledgers');
        Schema::dropIfExists('customer_ledgers');
    }

    private function ensureLedger(string $tableName, string $partyColumn): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($partyColumn) {
            $this->base($table);
            $table->uuid($partyColumn)->index();
            $table->string('type')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('reference')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('entry_at')->nullable();
        });
    }

    private function addColumnIfMissing(string $tableName, string $column, string $type = 'string'): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $type) {
            match ($type) {
                'uuid' => $table->uuid($column)->nullable()->index(),
                'decimal' => $table->decimal($column, 14, 2)->default(0),
                'date' => $table->date($column)->nullable(),
                default => $table->string($column)->nullable(),
            };
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
};
