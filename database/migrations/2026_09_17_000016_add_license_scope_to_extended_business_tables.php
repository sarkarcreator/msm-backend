<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'imei_registry',
        'imei_movements',
        'warranty_claims',
        'sale_returns',
        'sale_return_items',
        'purchase_returns',
        'purchase_return_items',
        'trader_companies',
        'trader_brands',
        'trader_territories',
        'trader_routes',
        'trader_salesmen',
        'trader_retailers',
        'trader_delivery_challans',
        'trader_recoveries',
        'trader_salesman_ledgers',
        'trader_distributor_ledgers',
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
