<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addJsonColumnIfMissing('products', 'packaging_units');

        foreach (['sale_items', 'purchase_items', 'inventory_transactions'] as $table) {
            $this->addStringColumnIfMissing($table, 'selected_unit', 80);
            $this->addStringColumnIfMissing($table, 'selected_unit_label', 190);
            $this->addIntegerColumnIfMissing($table, 'stock_quantity');
            $this->addDecimalColumnIfMissing($table, 'conversion_factor');
            $this->addStringColumnIfMissing($table, 'unit_barcode', 190);
        }
    }

    public function down(): void
    {
        foreach (['sale_items', 'purchase_items', 'inventory_transactions'] as $table) {
            $this->dropColumns($table, ['selected_unit', 'selected_unit_label', 'stock_quantity', 'conversion_factor', 'unit_barcode']);
        }

        $this->dropColumns('products', ['packaging_units']);
    }

    private function addJsonColumnIfMissing(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->json($column)->nullable());
    }

    private function addStringColumnIfMissing(string $table, string $column, int $length): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->string($column, $length)->nullable());
    }

    private function addIntegerColumnIfMissing(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->integer($column)->nullable());
    }

    private function addDecimalColumnIfMissing(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $schema) => $schema->decimal($column, 14, 4)->nullable());
    }

    private function dropColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $schema) use ($table, $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $schema->dropColumn($column);
                }
            }
        });
    }
};
