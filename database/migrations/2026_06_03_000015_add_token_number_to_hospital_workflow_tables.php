<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $tables = [
        'hospital_prescriptions',
        'hospital_orders',
        'hospital_tasks',
        'lab_reports',
        'radiology_reports',
        'hospital_bills',
        'hospital_bill_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'token_number')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('token_number')->nullable()->index()->after('patient_uuid');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'token_number')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('token_number');
            });
        }
    }
};
