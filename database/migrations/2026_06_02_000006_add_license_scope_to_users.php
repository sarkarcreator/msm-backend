<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addColumnIfMissing('users', 'license_uuid', 'uuid');
        $this->addColumnIfMissing('users', 'business_type');
        $this->addColumnIfMissing('users', 'shop_name');
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['license_uuid', 'business_type', 'shop_name'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addColumnIfMissing(string $tableName, string $column, string $type = 'string'): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $type) {
            match ($type) {
                'uuid' => $table->uuid($column)->nullable()->index(),
                default => $table->string($column)->nullable(),
            };
        });
    }
};
