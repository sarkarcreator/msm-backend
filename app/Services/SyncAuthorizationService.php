<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

class SyncAuthorizationService
{
    private array $licenseScopedEntities = [
        'products', 'categories', 'brands', 'customers', 'customer_ledgers', 'suppliers',
        'supplier_ledgers', 'sales', 'sale_items', 'purchases', 'purchase_items',
        'expenses', 'repairs', 'repair_updates', 'payments', 'cashbook', 'users',
        'settings', 'notifications', 'inventory_transactions', 'manual_repair_receipts',
        'mobile_wallet_transactions', 'patients', 'assistants', 'hospital_prescriptions',
        'hospital_orders', 'hospital_tasks', 'lab_reports', 'radiology_reports',
        'hospital_bills', 'hospital_bill_items', 'master_catalogs',
        'imei_registry', 'imei_movements', 'warranty_claims', 'sale_returns',
        'sale_return_items', 'purchase_returns', 'purchase_return_items',
        'trader_companies', 'trader_brands', 'trader_territories', 'trader_routes',
        'trader_salesmen', 'trader_retailers', 'trader_delivery_challans',
        'trader_recoveries', 'trader_salesman_ledgers', 'trader_distributor_ledgers',
    ];

    private array $hospitalOnlyEntities = [
        'patients', 'assistants', 'hospital_prescriptions', 'hospital_orders', 'hospital_tasks',
        'lab_reports', 'radiology_reports', 'hospital_bills', 'hospital_bill_items',
    ];

    private array $mobileOnlyEntities = [
        'imei_registry', 'imei_movements', 'warranty_claims', 'sale_returns',
        'sale_return_items', 'purchase_returns', 'purchase_return_items',
    ];

    private array $repairOnlyEntities = ['repairs', 'repair_updates', 'manual_repair_receipts'];

    private array $tradersOnlyEntities = [
        'trader_companies', 'trader_brands', 'trader_territories', 'trader_routes',
        'trader_salesmen', 'trader_retailers', 'trader_delivery_challans',
        'trader_recoveries', 'trader_salesman_ledgers', 'trader_distributor_ledgers',
    ];

    public function authorizeOperation(?User $actor, string $entity, string $action, array $data = []): array
    {
        $entity = str_replace('-', '_', $entity);
        $role = optional($actor?->role)->name;

        if ($role === 'Super Admin') {
            return ['allowed' => true, 'data' => $data];
        }

        if (! in_array($entity, $this->licenseScopedEntities, true)) {
            return ['allowed' => true, 'data' => $data];
        }

        if (! $actor?->license_uuid) {
            return ['allowed' => false, 'reason' => 'Missing tenant scope'];
        }

        $businessType = $actor->business_type ?: 'General Store';
        $businessKey = $this->businessTypeKey($businessType);

        if (in_array($entity, $this->hospitalOnlyEntities, true) && $businessKey !== 'hospital') {
            return ['allowed' => false, 'reason' => 'Hospital entity is not available for this tenant'];
        }

        if (in_array($entity, $this->mobileOnlyEntities, true) && $businessKey !== 'mobile_shop') {
            return ['allowed' => false, 'reason' => 'Mobile shop entity is not available for this tenant'];
        }

        if (in_array($entity, $this->repairOnlyEntities, true) && ! in_array($businessKey, ['mobile_shop', 'electronics_store'], true)) {
            return ['allowed' => false, 'reason' => 'Repair entity is not available for this tenant'];
        }

        if (in_array($entity, $this->tradersOnlyEntities, true) && $businessKey !== 'traders') {
            return ['allowed' => false, 'reason' => 'Traders entity is not available for this tenant'];
        }

        $data['license_uuid'] = $actor->license_uuid;
        $data['business_type'] = $businessType;

        return ['allowed' => true, 'data' => $data];
    }

    public function scopeQuery($query, string $table, ?User $actor, string $entity)
    {
        $role = optional($actor?->role)->name;
        $entity = str_replace('-', '_', $entity);

        if ($role === 'Super Admin' || ! in_array($entity, $this->licenseScopedEntities, true)) {
            return $query;
        }

        if (! Schema::hasColumn($table, 'license_uuid')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('license_uuid', $actor?->license_uuid);
    }

    public function businessTypeKey(?string $type): string
    {
        $key = strtolower(str_replace([' ', '-'], '_', trim((string) $type)));

        return match ($key) {
            'mobile', 'mobile_shop' => 'mobile_shop',
            'hospital' => 'hospital',
            'trader', 'traders', 'distribution' => 'traders',
            'electronics', 'electronics_store' => 'electronics_store',
            'general', 'general_store', 'grocery', 'grocery_store', 'retail_shop', 'shopping_mall' => 'general_store',
            default => $key,
        };
    }
}
