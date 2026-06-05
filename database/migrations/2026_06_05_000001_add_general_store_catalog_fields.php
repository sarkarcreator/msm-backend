<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addMasterCatalogFields();
        $this->addProductVariantFields();
    }

    public function down(): void
    {
        $this->dropColumns('master_catalogs', [
            'product_name', 'subcategory', 'barcode', 'pack_size', 'default_cost', 'default_price',
        ]);
        $this->dropColumns('products', ['variant_type', 'pack_size']);
    }

    private function addMasterCatalogFields(): void
    {
        if (! Schema::hasTable('master_catalogs')) {
            return;
        }

        Schema::table('master_catalogs', function (Blueprint $table) {
            if (! Schema::hasColumn('master_catalogs', 'product_name')) {
                $table->string('product_name')->nullable()->after('name')->index();
            }
            if (! Schema::hasColumn('master_catalogs', 'subcategory')) {
                $table->string('subcategory')->nullable()->after('category')->index();
            }
            if (! Schema::hasColumn('master_catalogs', 'barcode')) {
                $table->string('barcode')->nullable()->after('brand')->index();
            }
            if (! Schema::hasColumn('master_catalogs', 'pack_size')) {
                $table->string('pack_size')->nullable()->after('barcode');
            }
            if (! Schema::hasColumn('master_catalogs', 'default_cost')) {
                $table->decimal('default_cost', 14, 2)->default(0)->after('unit');
            }
            if (! Schema::hasColumn('master_catalogs', 'default_price')) {
                $table->decimal('default_price', 14, 2)->default(0)->after('default_cost');
            }
        });
    }

    private function addProductVariantFields(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'variant_type')) {
                $table->string('variant_type')->nullable()->after('unit')->index();
            }
            if (! Schema::hasColumn('products', 'pack_size')) {
                $table->string('pack_size')->nullable()->after('variant_type');
            }
        });
    }

    private function dropColumns(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
