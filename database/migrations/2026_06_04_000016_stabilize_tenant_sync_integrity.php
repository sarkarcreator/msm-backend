<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tenantTables = [
        'categories', 'brands', 'suppliers', 'supplier_ledgers', 'customers', 'customer_ledgers',
        'products', 'inventory_transactions', 'sales', 'sale_items', 'purchases', 'purchase_items',
        'expenses', 'payments', 'cashbook', 'repairs', 'repair_updates', 'manual_repair_receipts',
        'mobile_wallet_transactions', 'patients', 'assistants', 'hospital_prescriptions',
        'hospital_orders', 'hospital_tasks', 'lab_reports', 'radiology_reports', 'hospital_bills',
        'hospital_bill_items', 'master_catalogs', 'settings', 'notifications',
    ];

    public function up(): void
    {
        foreach ($this->tenantTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'license_uuid')) {
                    $table->uuid('license_uuid')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'business_type')) {
                    $table->string('business_type')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'revision')) {
                    $table->unsignedBigInteger('revision')->default(1)->index();
                }
                if (! Schema::hasColumn($tableName, 'quarantined_at')) {
                    $table->timestamp('quarantined_at')->nullable()->index();
                }
            });

            DB::table($tableName)
                ->whereNull('license_uuid')
                ->whereNull('quarantined_at')
                ->update([
                    'quarantined_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('sync_queue')) {
            Schema::table('sync_queue', function (Blueprint $table) {
                if (! Schema::hasColumn('sync_queue', 'license_uuid')) {
                    $table->uuid('license_uuid')->nullable()->index();
                }
                if (! Schema::hasColumn('sync_queue', 'business_type')) {
                    $table->string('business_type')->nullable()->index();
                }
                if (! Schema::hasColumn('sync_queue', 'record_updated_at')) {
                    $table->timestamp('record_updated_at')->nullable()->index();
                }
                if (! Schema::hasColumn('sync_queue', 'revision')) {
                    $table->unsignedBigInteger('revision')->default(1)->index();
                }
                if (! Schema::hasColumn('sync_queue', 'is_tombstone')) {
                    $table->boolean('is_tombstone')->default(false)->index();
                }
            });
        }

        if (Schema::hasTable('patients') && ! $this->hasIndex('patients', 'patients_license_token_index')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->index(['license_uuid', 'token_number'], 'patients_license_token_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('patients') && $this->hasIndex('patients', 'patients_license_token_index')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->dropIndex('patients_license_token_index');
            });
        }

        foreach ($this->tenantTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['quarantined_at', 'revision'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('sync_queue')) {
            Schema::table('sync_queue', function (Blueprint $table) {
                foreach (['license_uuid', 'business_type', 'record_updated_at', 'revision', 'is_tombstone'] as $column) {
                    if (Schema::hasColumn('sync_queue', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($item) => ($item['name'] ?? '') === $index);
    }
};
