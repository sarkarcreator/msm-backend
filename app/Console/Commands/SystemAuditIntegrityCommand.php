<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemAuditIntegrityCommand extends Command
{
    protected $signature = 'system:audit-integrity {--json : Output JSON report}';

    protected $description = 'Audit cross-module tenant, sync, inventory and financial integrity without modifying data.';

    private array $tenantTables = [
        'products', 'categories', 'brands', 'customers', 'customer_ledgers', 'suppliers',
        'supplier_ledgers', 'sales', 'sale_items', 'purchases', 'purchase_items',
        'expenses', 'payments', 'cashbook', 'repairs', 'repair_updates',
        'inventory_transactions', 'manual_repair_receipts', 'mobile_wallet_transactions',
        'patients', 'assistants', 'hospital_prescriptions', 'hospital_orders',
        'hospital_tasks', 'lab_reports', 'radiology_reports', 'hospital_bills',
        'hospital_bill_items', 'master_catalogs', 'imei_registry', 'imei_movements',
        'warranty_claims', 'sale_returns', 'sale_return_items', 'purchase_returns',
        'purchase_return_items', 'trader_companies', 'trader_brands',
        'trader_territories', 'trader_routes', 'trader_salesmen', 'trader_retailers',
        'trader_delivery_challans', 'trader_recoveries', 'trader_salesman_ledgers',
        'trader_distributor_ledgers',
    ];

    public function handle(): int
    {
        $report = [
            'generated_at' => now()->toISOString(),
            'tenant_scope' => $this->tenantScopeReport(),
            'business_type_mismatches' => $this->businessTypeMismatches(),
            'sync_queue' => $this->syncQueueReport(),
            'orphan_records' => $this->orphanReport(),
            'duplicate_numbers' => $this->duplicateNumberReport(),
            'financial_mismatches' => $this->financialMismatchReport(),
            'inventory_mismatches' => $this->inventoryMismatchReport(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('MSM ERP System Integrity Audit');
        $this->line('Generated At: ' . $report['generated_at']);
        foreach ($report as $section => $rows) {
            if ($section === 'generated_at') {
                continue;
            }
            $this->newLine();
            $this->line(str_replace('_', ' ', ucwords($section, '_')) . ': ' . count($rows));
            if (count($rows) > 0) {
                $arrayRows = array_map(fn ($row) => (array) $row, $rows);
                $this->table(array_keys($arrayRows[0]), $arrayRows);
            }
        }

        return self::SUCCESS;
    }

    private function tenantScopeReport(): array
    {
        $rows = [];
        foreach ($this->tenantTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'license_uuid')) {
                $rows[] = ['table' => $table, 'issue' => 'missing_license_uuid_column', 'count' => null];
                continue;
            }
            $query = DB::table($table)->whereNull('license_uuid');
            $this->withoutDeleted($query, $table);
            $count = $query->count();
            if ($count > 0) {
                $rows[] = ['table' => $table, 'issue' => 'records_missing_license_uuid', 'count' => $count];
            }
        }

        return $rows;
    }

    private function businessTypeMismatches(): array
    {
        $rows = [];
        foreach ($this->tenantTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'license_uuid') || ! Schema::hasColumn($table, 'business_type')) {
                continue;
            }
            if (! Schema::hasTable('licenses') || ! Schema::hasColumn('licenses', 'business_type')) {
                continue;
            }
            $query = DB::table($table)
                ->join('licenses', "{$table}.license_uuid", '=', 'licenses.uuid')
                ->whereNotNull("{$table}.business_type")
                ->whereColumn("{$table}.business_type", '!=', 'licenses.business_type');
            $this->withoutDeleted($query, $table);
            $count = $query->count();
            if ($count > 0) {
                $rows[] = ['table' => $table, 'issue' => 'business_type_differs_from_license', 'count' => $count];
            }
        }

        return $rows;
    }

    private function syncQueueReport(): array
    {
        if (! Schema::hasTable('sync_queue')) {
            return [['table' => 'sync_queue', 'issue' => 'missing_table', 'count' => null]];
        }

        $rows = [];
        foreach (['license_uuid', 'business_type', 'record_updated_at', 'revision', 'is_tombstone'] as $column) {
            if (! Schema::hasColumn('sync_queue', $column)) {
                $rows[] = ['table' => 'sync_queue', 'issue' => "missing_{$column}_column", 'count' => null];
            }
        }

        if (Schema::hasColumn('sync_queue', 'license_uuid')) {
            $count = DB::table('sync_queue')->whereNull('license_uuid')->where('entity', '!=', 'licenses')->count();
            if ($count > 0) {
                $rows[] = ['table' => 'sync_queue', 'issue' => 'tenant_operations_missing_license_uuid', 'count' => $count];
            }
        }

        return $rows;
    }

    private function orphanReport(): array
    {
        $checks = [
            ['sale_items', 'sales', 'sale_uuid', 'uuid'],
            ['purchase_items', 'purchases', 'purchase_uuid', 'uuid'],
            ['customer_ledgers', 'customers', 'customer_uuid', 'uuid'],
            ['supplier_ledgers', 'suppliers', 'supplier_uuid', 'uuid'],
            ['inventory_transactions', 'products', 'product_uuid', 'uuid'],
            ['hospital_prescriptions', 'patients', 'patient_uuid', 'uuid'],
            ['hospital_orders', 'patients', 'patient_uuid', 'uuid'],
            ['hospital_tasks', 'patients', 'patient_uuid', 'uuid'],
            ['hospital_bills', 'patients', 'patient_uuid', 'uuid'],
            ['hospital_bill_items', 'hospital_bills', 'bill_uuid', 'uuid'],
            ['trader_brands', 'trader_companies', 'company_uuid', 'uuid'],
            ['trader_routes', 'trader_territories', 'territory_uuid', 'uuid'],
        ];

        $rows = [];
        foreach ($checks as [$child, $parent, $childColumn, $parentColumn]) {
            if (! $this->canJoin($child, $parent, $childColumn, $parentColumn)) {
                continue;
            }
            $query = DB::table($child)
                ->leftJoin($parent, "{$child}.{$childColumn}", '=', "{$parent}.{$parentColumn}")
                ->whereNotNull("{$child}.{$childColumn}")
                ->whereNull("{$parent}.{$parentColumn}");
            $this->withoutDeleted($query, $child);
            $count = $query->count();
            if ($count > 0) {
                $rows[] = [
                    'table' => $child,
                    'issue' => "orphan_{$childColumn}",
                    'parent_table' => $parent,
                    'count' => $count,
                ];
            }
        }

        return $rows;
    }

    private function duplicateNumberReport(): array
    {
        $checks = [
            ['sales', 'invoice_number'],
            ['purchases', 'invoice_number'],
            ['patients', 'token_number'],
            ['hospital_bills', 'bill_number'],
            ['sale_returns', 'return_number'],
            ['purchase_returns', 'return_number'],
            ['trader_delivery_challans', 'challan_number'],
        ];

        $rows = [];
        foreach ($checks as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'license_uuid') || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $query = DB::table($table)
                ->select('license_uuid', $column, DB::raw('COUNT(*) as total'))
                ->whereNotNull($column)
                ->groupBy('license_uuid', $column)
                ->havingRaw('COUNT(*) > 1');
            $this->withoutDeleted($query, $table);
            $count = DB::query()->fromSub($query, 'duplicates')->count();
            if ($count > 0) {
                $rows[] = ['table' => $table, 'issue' => "duplicate_{$column}", 'count' => $count];
            }
        }

        return $rows;
    }

    private function financialMismatchReport(): array
    {
        $rows = [];
        if (Schema::hasTable('sales')) {
            $query = DB::table('sales')
                ->whereRaw('ABS(COALESCE(total, 0) - COALESCE(paid, 0) - COALESCE(balance, 0)) > 0.01');
            $this->withoutDeleted($query, 'sales');
            $count = $query->count();
            if ($count > 0) {
                $rows[] = ['table' => 'sales', 'issue' => 'total_paid_balance_mismatch', 'count' => $count];
            }
        }

        if (Schema::hasTable('hospital_bills')) {
            $query = DB::table('hospital_bills')
                ->whereRaw('ABS(COALESCE(grand_total, 0) - COALESCE(paid, 0) - COALESCE(balance, 0)) > 0.01');
            $this->withoutDeleted($query, 'hospital_bills');
            $count = $query->count();
            if ($count > 0) {
                $rows[] = ['table' => 'hospital_bills', 'issue' => 'grand_total_paid_balance_mismatch', 'count' => $count];
            }
        }

        return $rows;
    }

    private function inventoryMismatchReport(): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('inventory_transactions')) {
            return [];
        }

        if (! Schema::hasColumn('products', 'quantity') || ! Schema::hasColumn('inventory_transactions', 'quantity')) {
            return [];
        }

        $query = DB::table('products')
            ->leftJoin('inventory_transactions', function ($join) {
                $join->on('products.uuid', '=', 'inventory_transactions.product_uuid')
                    ->whereNull('inventory_transactions.deleted_at');
            })
            ->whereNull('products.deleted_at')
            ->select(
                'products.uuid',
                'products.license_uuid',
                'products.product_name',
                'products.quantity',
                DB::raw('COALESCE(SUM(inventory_transactions.quantity), 0) as movement_quantity')
            )
            ->groupBy('products.uuid', 'products.license_uuid', 'products.product_name', 'products.quantity')
            ->havingRaw('ABS(products.quantity - COALESCE(SUM(inventory_transactions.quantity), 0)) > 0');

        $count = DB::query()->fromSub($query, 'inventory_mismatches')->count();

        return $count > 0
            ? [['table' => 'products', 'issue' => 'quantity_differs_from_inventory_movements', 'count' => $count]]
            : [];
    }

    private function canJoin(string $child, string $parent, string $childColumn, string $parentColumn): bool
    {
        return Schema::hasTable($child)
            && Schema::hasTable($parent)
            && Schema::hasColumn($child, $childColumn)
            && Schema::hasColumn($parent, $parentColumn);
    }

    private function withoutDeleted($query, string $table): void
    {
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull("{$table}.deleted_at");
        }
    }
}
