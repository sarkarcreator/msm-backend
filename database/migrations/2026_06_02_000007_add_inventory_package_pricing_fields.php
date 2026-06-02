<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addColumnIfMissing('products', 'package_quantity', 'integer');
        $this->addColumnIfMissing('products', 'units_per_package', 'integer');
        $this->addColumnIfMissing('products', 'loose_quantity', 'integer');
        $this->addColumnIfMissing('products', 'unit_cost_price', 'decimal');
        $this->addColumnIfMissing('products', 'unit_sale_price', 'decimal');
        $this->addColumnIfMissing('products', 'package_cost_price', 'decimal');
        $this->addColumnIfMissing('products', 'total_cost', 'decimal');
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['package_quantity', 'units_per_package', 'loose_quantity', 'unit_cost_price', 'unit_sale_price', 'package_cost_price', 'total_cost'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addColumnIfMissing(string $tableName, string $column, string $type): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $type) {
            match ($type) {
                'integer' => $table->integer($column)->default(0),
                default => $table->decimal($column, 14, 2)->default(0),
            };
        });
    }
};
