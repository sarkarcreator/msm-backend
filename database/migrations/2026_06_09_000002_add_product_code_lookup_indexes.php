<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addProductCode('products');
        $this->addProductCode('master_catalogs');
        $this->safeIndex('products', ['license_uuid', 'product_code'], 'products_license_product_code_index');
        $this->safeIndex('products', ['license_uuid', 'brand'], 'products_license_brand_lookup_index');
        $this->safeIndex('products', ['license_uuid', 'product_name'], 'products_license_name_lookup_index');
        $this->safeIndex('master_catalogs', ['business_type', 'product_code'], 'catalog_business_product_code_index');
        $this->safeIndex('master_catalogs', ['business_type', 'brand'], 'catalog_business_brand_lookup_index');
    }

    public function down(): void
    {
        // Additive production migration: keep columns and indexes for backward compatibility.
    }

    private function addProductCode(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'product_code')) {
            return;
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->string('product_code')->nullable());
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
