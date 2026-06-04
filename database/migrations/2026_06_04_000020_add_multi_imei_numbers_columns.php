<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['products', 'sale_items', 'purchase_items', 'imei_registry'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'imei_numbers')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->json('imei_numbers')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'sale_items', 'purchase_items', 'imei_registry'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'imei_numbers')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('imei_numbers');
            });
        }
    }
};
