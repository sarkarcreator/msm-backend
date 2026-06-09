<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addProductFields();
        $this->addCatalogFields();
        $this->safeIndex('products', ['license_uuid', 'secondary_barcode'], 'products_license_secondary_barcode_index');
        $this->safeIndex('products', ['license_uuid', 'qr_code'], 'products_license_qr_code_index');
        $this->safeIndex('products', ['license_uuid', 'box_barcode'], 'products_license_box_barcode_index');
        $this->safeIndex('products', ['license_uuid', 'carton_barcode'], 'products_license_carton_barcode_index');
        $this->safeIndex('master_catalogs', ['business_type', 'secondary_barcode'], 'catalog_business_secondary_barcode_index');
        $this->safeIndex('master_catalogs', ['business_type', 'qr_code'], 'catalog_business_qr_code_index');
    }

    public function down(): void
    {
        // Additive production migration: keep columns for backward compatibility.
    }

    private function addProductFields(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'secondary_barcode' => 'string',
                'qr_code' => 'string',
                'box_barcode' => 'string',
                'carton_barcode' => 'string',
            ] as $column => $type) {
                if (Schema::hasColumn('products', $column)) {
                    continue;
                }

                $table->string($column)->nullable();
            }
        });
    }

    private function addCatalogFields(): void
    {
        if (! Schema::hasTable('master_catalogs')) {
            return;
        }

        Schema::table('master_catalogs', function (Blueprint $table) {
            foreach ([
                'secondary_barcode' => 'string',
                'qr_code' => 'string',
                'box_barcode' => 'string',
                'carton_barcode' => 'string',
            ] as $column => $type) {
                if (Schema::hasColumn('master_catalogs', $column)) {
                    continue;
                }

                $table->string($column)->nullable();
            }
        });
    }

    private function safeIndex(string $tableName, array $columns, string $name): void
    {
        if (! Schema::hasTable($tableName) || $this->hasIndex($tableName, $name)) {
            return;
        }

        if (! collect($columns)->every(fn ($column) => Schema::hasColumn($tableName, $column))) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->index($columns, $name));
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($item) => ($item['name'] ?? '') === $index);
    }
};
