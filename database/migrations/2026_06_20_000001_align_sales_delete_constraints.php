<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->replaceForeignKey('sales', 'sales_customer_id_foreign', 'customer_id', 'customers', 'id', 'SET NULL', true);
        $this->replaceForeignKey('sale_items', 'sale_items_sale_id_foreign', 'sale_id', 'sales', 'id', 'CASCADE');
    }

    public function down(): void
    {
        $this->replaceForeignKey('sales', 'sales_customer_id_foreign', 'customer_id', 'customers', 'id', 'RESTRICT', true);
        $this->replaceForeignKey('sale_items', 'sale_items_sale_id_foreign', 'sale_id', 'sales', 'id', 'RESTRICT');
    }

    private function replaceForeignKey(string $table, string $constraint, string $column, string $referencesTable, string $referencesColumn, string $onDelete, bool $nullable = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($referencesTable)) {
            return;
        }

        if ($this->foreignKeyExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }

        if ($nullable) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED NULL");
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencesTable}`(`{$referencesColumn}`) ON DELETE {$onDelete}");
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = DB::getDatabaseName();
        return (bool) DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
