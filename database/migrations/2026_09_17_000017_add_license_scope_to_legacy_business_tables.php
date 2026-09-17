<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Legacy business tables that pre-date the tenant-scope migrations.
     * Add scope columns defensively so existing deployments remain compatible.
     * We intentionally do not backfill ambiguous legacy rows.
     */
    private array $tables = [
        'credits',
        'customer_payments',
        'imei_numbers',
        'repair_jobs',
        'repair_status',
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
