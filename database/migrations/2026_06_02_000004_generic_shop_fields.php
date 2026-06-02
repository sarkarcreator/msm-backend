<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addColumnIfMissing('products', 'sku');
        $this->addColumnIfMissing('products', 'unit');
        $this->addColumnIfMissing('products', 'batch_number');
        $this->addColumnIfMissing('products', 'expiry_date', 'date');
        $this->addColumnIfMissing('products', 'manufacturer');
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['sku', 'unit', 'batch_number', 'expiry_date', 'manufacturer'] as $column) {
                if (Schema::hasColumn('products', $column)) {
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
                'date' => $table->date($column)->nullable(),
                default => $table->string($column)->nullable(),
            };
        });
    }
};
