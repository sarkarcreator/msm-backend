<?php

namespace App\Services;

use App\Models\SyncQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncService
{
    private array $models = [
        'products' => \App\Models\Product::class,
        'categories' => \App\Models\Category::class,
        'brands' => \App\Models\Brand::class,
        'customers' => \App\Models\Customer::class,
        'customer_ledgers' => \App\Models\CustomerLedger::class,
        'sales' => \App\Models\Sale::class,
        'sale_items' => \App\Models\SaleItem::class,
        'expenses' => \App\Models\Expense::class,
        'repairs' => \App\Models\Repair::class,
        'repair_updates' => \App\Models\RepairUpdate::class,
        'suppliers' => \App\Models\Supplier::class,
        'supplier_ledgers' => \App\Models\SupplierLedger::class,
        'purchases' => \App\Models\Purchase::class,
        'purchase_items' => \App\Models\PurchaseItem::class,
        'payments' => \App\Models\Payment::class,
        'cashbook' => \App\Models\CashbookEntry::class,
        'users' => \App\Models\User::class,
        'roles' => \App\Models\Role::class,
        'permissions' => \App\Models\Permission::class,
        'settings' => \App\Models\Setting::class,
        'notifications' => \App\Models\Notification::class,
        'manual_repair_receipts' => \App\Models\ManualRepairReceipt::class,
        'mobile_wallet_transactions' => \App\Models\MobileWalletTransaction::class,
        'patients' => \App\Models\Patient::class,
        'assistants' => \App\Models\Assistant::class,
        'hospital_prescriptions' => \App\Models\HospitalPrescription::class,
        'hospital_orders' => \App\Models\HospitalOrder::class,
        'hospital_tasks' => \App\Models\HospitalTask::class,
        'lab_reports' => \App\Models\LabReport::class,
        'radiology_reports' => \App\Models\RadiologyReport::class,
        'hospital_bills' => \App\Models\HospitalBill::class,
        'hospital_bill_items' => \App\Models\HospitalBillItem::class,
        'master_catalogs' => \App\Models\MasterCatalog::class,
        'medicines' => \App\Models\Medicine::class,
        'licenses' => \App\Models\License::class,
        'audit_logs' => \App\Models\AuditLog::class,
        'inventory_transactions' => \App\Models\InventoryTransaction::class,
    ];

    private array $licenseScopedEntities = [
        'products', 'categories', 'brands', 'customers', 'customer_ledgers', 'suppliers',
        'supplier_ledgers', 'sales', 'sale_items', 'purchases', 'purchase_items',
        'expenses', 'repairs', 'repair_updates', 'payments', 'cashbook', 'users',
        'settings', 'notifications', 'inventory_transactions', 'manual_repair_receipts',
        'mobile_wallet_transactions', 'patients', 'assistants', 'hospital_prescriptions',
        'hospital_orders', 'hospital_tasks', 'lab_reports', 'radiology_reports',
        'hospital_bills', 'hospital_bill_items', 'master_catalogs',
    ];

    public function apply(string $deviceId, array $operations, ?User $actor = null): array
    {
        return DB::transaction(function () use ($deviceId, $operations, $actor) {
            return collect($operations)->map(function (array $operation) use ($deviceId, $actor) {
                $model = $this->models[$operation['entity']] ?? null;
                if (! $model) {
                    return ['uuid' => $operation['uuid'], 'status' => 'rejected', 'reason' => 'Unknown entity'];
                }

                $data = $operation['data'] ?? [];
                $actorRole = optional($actor?->role)->name;
                if ($actorRole !== 'Super Admin' && in_array($operation['entity'], $this->licenseScopedEntities, true)) {
                    if ($actor?->license_uuid) {
                        $data['license_uuid'] = $actor->license_uuid;
                    }
                    if ($actor?->business_type) {
                        $data['business_type'] = $actor->business_type;
                    }
                }

                $query = app($model)->newQuery();
                $data = $this->onlyTableColumns(app($model)->getTable(), $data);
                $record = $query->where('uuid', $operation['uuid'])->first();

                if ($record && $record->updated_at->gt($operation['client_updated_at'])) {
                    return ['uuid' => $operation['uuid'], 'status' => 'conflict', 'server' => $record];
                }

                if ($operation['action'] === 'force_delete') {
                    $record?->forceDelete();
                } elseif ($operation['action'] === 'delete') {
                    $record?->delete();
                } else {
                    $query->updateOrCreate(['uuid' => $operation['uuid']], $data);
                }

                SyncQueue::create([
                    'uuid' => $operation['uuid'],
                    'device_id' => $deviceId,
                    'entity' => $operation['entity'],
                    'action' => $operation['action'],
                    'payload' => $data,
                ]);

                return ['uuid' => $operation['uuid'], 'status' => 'accepted'];
            })->all();
        });
    }

    private function onlyTableColumns(string $table, array $data): array
    {
        if (! Schema::hasTable($table)) {
            return $data;
        }

        $allowed = array_flip(Schema::getColumnListing($table));
        return array_intersect_key($data, $allowed);
    }
}
