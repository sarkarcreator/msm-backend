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
        // automatically when the database has exactly one tenant license;
        // with multiple tenants, leaving them unassigned is safer than
        // exposing one tenant's medicine data to another tenant.
        if (Schema::hasColumn('medicines', 'license_uuid') && Schema::hasTable('licenses')) {
            $tenantLicenses = DB::table('licenses')
                ->whereNull('deleted_at')
                ->whereNotNull('uuid')
                ->get(['uuid', 'business_type']);

            if ($tenantLicenses->count() === 1) {
                $license = $tenantLicenses->first();

                DB::table('medicines')
                    ->whereNull('license_uuid')
                    ->update([
                        'license_uuid' => $license->uuid,
                        'business_type' => $license->business_type,
                    ]);
            }
        }
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
