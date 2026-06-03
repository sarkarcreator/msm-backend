<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'categories', 'brands', 'suppliers', 'supplier_ledgers', 'customers', 'customer_ledgers',
        'products', 'inventory_transactions', 'sales', 'sale_items', 'purchases', 'purchase_items',
        'expenses', 'payments', 'cashbook', 'repairs', 'repair_updates', 'manual_repair_receipts',
        'mobile_wallet_transactions', 'patients', 'assistants', 'hospital_prescriptions',
        'hospital_orders', 'hospital_tasks', 'lab_reports', 'radiology_reports', 'hospital_bills',
        'hospital_bill_items', 'master_catalogs', 'settings', 'notifications',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
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
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'business_type')) {
                    $table->dropColumn('business_type');
                }

                if (Schema::hasColumn($tableName, 'license_uuid')) {
                    $table->dropColumn('license_uuid');
                }
            });
        }
    }
};
