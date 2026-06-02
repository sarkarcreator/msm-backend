<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('patients') && ! Schema::hasColumn('patients', 'medicine_days')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->integer('medicine_days')->default(0)->after('medicine');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('patients') && Schema::hasColumn('patients', 'medicine_days')) {
            Schema::table('patients', function (Blueprint $table) {
                $table->dropColumn('medicine_days');
            });
        }
    }
};
