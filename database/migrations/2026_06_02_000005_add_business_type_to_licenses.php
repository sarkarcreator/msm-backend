<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('licenses') || Schema::hasColumn('licenses', 'business_type')) {
            return;
        }

        Schema::table('licenses', function (Blueprint $table) {
            $table->string('business_type')->default('Mobile Shop')->after('owner_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('licenses') || ! Schema::hasColumn('licenses', 'business_type')) {
            return;
        }

        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('business_type');
        });
    }
};
