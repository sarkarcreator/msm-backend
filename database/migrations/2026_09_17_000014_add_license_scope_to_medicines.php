<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('medicines')) {
            return;
        }

        Schema::table('medicines', function (Blueprint $table) {
            if (! Schema::hasColumn('medicines', 'license_uuid')) {
                $table->uuid('license_uuid')->nullable()->index();
            }

            if (! Schema::hasColumn('medicines', 'business_type')) {
                $table->string('business_type')->nullable()->index();
            }
        });

        // Existing medicine rows pre-date tenant scoping. Only assign them
        // automatically when the database has exactly one usable tenant
        // license. With multiple tenants, leaving them unassigned is safer
        // than exposing one tenant's medicine data to another tenant.
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

        DB::table('medicines')
            ->whereNull('license_uuid')
            ->update([
                'license_uuid' => $license->uuid,
                'business_type' => $hasBusinessType ? ($license->business_type ?? null) : null,
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('medicines')) {
            return;
        }

        Schema::table('medicines', function (Blueprint $table) {
            if (Schema::hasColumn('medicines', 'business_type')) {
                $table->dropColumn('business_type');
            }

            if (Schema::hasColumn('medicines', 'license_uuid')) {
                $table->dropColumn('license_uuid');
            }
        });
    }
};
