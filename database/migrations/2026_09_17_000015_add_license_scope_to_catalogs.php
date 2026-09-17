<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = ['products', 'categories', 'brands'];

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

        // Existing catalog rows pre-date tenant scoping. If there is exactly
        // one active tenant, assigning those rows is safe and preserves the
        // current catalog. With multiple tenants, leave legacy rows unscoped
        // rather than exposing one tenant's catalog to another tenant.
        if (! Schema::hasTable('licenses') || ! Schema::hasColumn('licenses', 'uuid')) {
            return;
        }

        $licenseColumns = Schema::getColumnListing('licenses');
        $select = ['uuid'];
        $hasBusinessType = in_array('business_type', $licenseColumns, true);

        if ($hasBusinessType) {
            $select[] = 'business_type';
        }

        $query = DB::table('licenses')->whereNotNull('uuid');

        if (in_array('deleted_at', $licenseColumns, true)) {
            $query->whereNull('deleted_at');
        }

        $tenantLicenses = $query->get($select);

        if ($tenantLicenses->count() !== 1) {
            return;
        }

        $license = $tenantLicenses->first();
        $businessType = $hasBusinessType ? ($license->business_type ?? null) : null;

        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'license_uuid')) {
                continue;
            }

            DB::table($tableName)
                ->whereNull('license_uuid')
                ->update([
                    'license_uuid' => $license->uuid,
                    'business_type' => $businessType,
                ]);
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
