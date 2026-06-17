<?php

namespace App\Services;

use App\Models\SyncQueue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncService
{
    public function __construct(private SyncAuthorizationService $authorization)
    {
    }

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
        'imei_registry' => \App\Models\ImeiRegistry::class,
        'imei_movements' => \App\Models\ImeiMovement::class,
        'warranty_claims' => \App\Models\WarrantyClaim::class,
        'sale_returns' => \App\Models\SaleReturn::class,
        'sale_return_items' => \App\Models\SaleReturnItem::class,
        'purchase_returns' => \App\Models\PurchaseReturn::class,
        'purchase_return_items' => \App\Models\PurchaseReturnItem::class,
        'trader_companies' => \App\Models\TraderCompany::class,
        'trader_brands' => \App\Models\TraderBrand::class,
        'trader_territories' => \App\Models\TraderTerritory::class,
        'trader_routes' => \App\Models\TraderRoute::class,
        'trader_salesmen' => \App\Models\TraderSalesman::class,
        'trader_retailers' => \App\Models\TraderRetailer::class,
        'trader_delivery_challans' => \App\Models\TraderDeliveryChallan::class,
        'trader_recoveries' => \App\Models\TraderRecovery::class,
        'trader_salesman_ledgers' => \App\Models\TraderSalesmanLedger::class,
        'trader_distributor_ledgers' => \App\Models\TraderDistributorLedger::class,
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
        'imei_registry', 'imei_movements', 'warranty_claims', 'sale_returns',
        'sale_return_items', 'purchase_returns', 'purchase_return_items',
        'trader_companies', 'trader_brands', 'trader_territories', 'trader_routes',
        'trader_salesmen', 'trader_retailers', 'trader_delivery_challans',
        'trader_recoveries', 'trader_salesman_ledgers', 'trader_distributor_ledgers',
    ];

    public function apply(string $deviceId, array $operations, ?User $actor = null): array
    {
        // SECURITY: Validate operations structure to prevent injection attacks
        if (!$this->validateOperationsStructure($operations)) {
            return [['uuid' => null, 'status' => 'rejected', 'reason' => 'Invalid operations structure']];
        }
        
        return DB::transaction(function () use ($deviceId, $operations, $actor) {
            return collect($operations)->map(function (array $operation) use ($deviceId, $actor) {
                try {
                    $model = $this->models[$operation['entity']] ?? null;
                    if (! $model) {
                        return ['uuid' => $operation['uuid'] ?? null, 'status' => 'rejected', 'reason' => 'Unknown entity'];
                    }

                    $data = $operation['data'] ?? [];
                    
                    // SECURITY: Sanitize and validate the data array
                    $data = $this->sanitizeOperationData($data);
                    
                    $authorization = $this->authorization->authorizeOperation($actor, $operation['entity'], $operation['action'], $data);
                    if (! $authorization['allowed']) {
                        return ['uuid' => $operation['uuid'] ?? null, 'status' => 'rejected', 'reason' => $authorization['reason']];
                    }
                    $data = $authorization['data'];

                    $instance = app($model);
                    $table = $instance->getTable();
                    $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($model), true);
                    $query = $usesSoftDeletes ? $model::withTrashed() : $instance->newQuery();
                    $query = $this->authorization->scopeQuery($query, $table, $actor, $operation['entity']);
                    
                    // SECURITY: Only allow columns that exist in the table
                    $data = $this->normalizeDateTimes($this->onlyTableColumns($table, $data), $table);
                    
                    // SECURITY: Validate UUID format
                    $uuid = $this->sanitizeUuid($operation['uuid'] ?? '');
                    if (!$uuid) {
                        return ['uuid' => $operation['uuid'] ?? null, 'status' => 'rejected', 'reason' => 'Invalid UUID format'];
                    }
                    
                    $record = $query->where('uuid', $uuid)->first();

                    if ($record && isset($data['revision']) && isset($record->revision) && (int) $record->revision > (int) $data['revision']) {
                        $this->logConflict($operation, $deviceId, $data, $actor, 'revision_conflict');
                        return ['uuid' => $uuid, 'status' => 'conflict', 'reason' => 'revision_conflict', 'server' => $record];
                    }

                    $clientUpdatedAt = $this->safeTimestamp($operation['client_updated_at'] ?? null);
                    if ($record && $clientUpdatedAt && $record->updated_at && $record->updated_at->gt($clientUpdatedAt)) {
                        $this->logConflict($operation, $deviceId, $data, $actor, 'updated_at_conflict');
                        return ['uuid' => $uuid, 'status' => 'conflict', 'server' => $record];
                    }

                    if ($operation['action'] === 'force_delete') {
                        $record?->forceDelete();
                    } elseif ($operation['action'] === 'delete') {
                        $record?->delete();
                    } else {
                        // SECURITY: Use sanitized UUID
                        $query->updateOrCreate(['uuid' => $uuid], $data);
                    }

                    SyncQueue::create($this->syncQueuePayload($operation, $deviceId, $data, $actor, $uuid));

                    return ['uuid' => $uuid, 'status' => 'accepted'];
                } catch (Throwable $exception) {
                    return [
                        'uuid' => $uuid ?? null,
                        'status' => 'rejected',
                        'reason' => 'sync_validation_failed',
                        'message' => $exception->getMessage(),
                    ];
                }
            })->all();
        });
    }

    private function syncQueuePayload(array $operation, string $deviceId, array $data, ?User $actor, ?string $uuid = null): array
    {
        // SECURITY: Use sanitized UUID
        $payload = [
            'uuid' => $uuid ?? $operation['uuid'] ?? null,
            'device_id' => $deviceId,
            'entity' => $operation['entity'],
            'action' => $operation['action'],
            'payload' => $data,
            'synced_at' => $this->formatTimestamp(now()),
        ];

        if (Schema::hasColumn('sync_queue', 'license_uuid')) {
            $payload['license_uuid'] = $data['license_uuid'] ?? $actor?->license_uuid;
        }
        if (Schema::hasColumn('sync_queue', 'business_type')) {
            $payload['business_type'] = $data['business_type'] ?? $actor?->business_type;
        }
        if (Schema::hasColumn('sync_queue', 'record_updated_at')) {
            $payload['record_updated_at'] = $this->formatTimestamp($data['updated_at'] ?? now());
        }
        if (Schema::hasColumn('sync_queue', 'is_tombstone')) {
            $payload['is_tombstone'] = in_array($operation['action'], ['delete', 'force_delete'], true);
        }

        return $payload;
    }

    private function logConflict(array $operation, string $deviceId, array $data, ?User $actor, string $reason): void
    {
        // SECURITY: Use sanitized UUID
        $uuid = $this->sanitizeUuid($operation['uuid'] ?? '');
        $payload = $this->syncQueuePayload($operation, $deviceId, $data, $actor, $uuid);
        $payload['action'] = 'conflict';
        $payload['payload'] = array_merge($payload['payload'] ?? [], ['conflict_reason' => $reason]);
        SyncQueue::create($this->normalizeDateTimes($payload, 'sync_queue'));
    }

    private function onlyTableColumns(string $table, array $data): array
    {
        if (! Schema::hasTable($table)) {
            return $data;
        }

        $allowed = array_flip(Schema::getColumnListing($table));
        return array_intersect_key($data, $allowed);
    }

    private function normalizeDateTimes(array $data, string $table): array
    {
        if (! Schema::hasTable($table)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (! $this->isDateTimeField($table, $key)) {
                continue;
            }
            $data[$key] = $this->formatTimestamp($value);
        }

        return $data;
    }

    private function isDateTimeField(string $table, string $key): bool
    {
        if (! Schema::hasColumn($table, $key)) {
            return false;
        }

        if (in_array($key, ['created_at', 'updated_at', 'deleted_at', 'synced_at', 'record_updated_at'], true)) {
            return true;
        }

        return str_ends_with($key, '_at');
    }

    private function formatTimestamp($value): string
    {
        return $this->safeTimestamp($value)->format('Y-m-d H:i:s');
    }

    private function safeTimestamp($value): Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value;
            }
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value);
            }
            if ($value === null || $value === '') {
                return now();
            }
            return Carbon::parse($value);
        } catch (Throwable) {
            return now();
        }
    }
    
    /**
     * SECURITY: Validate the structure of operations array to prevent injection.
     */
    private function validateOperationsStructure(array $operations): bool
    {
        if (!is_array($operations)) {
            return false;
        }
        
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                return false;
            }
            
            // Must have required fields
            if (!isset($operation['uuid']) || !isset($operation['entity']) || !isset($operation['action'])) {
                return false;
            }
            
            // Validate action is one of the allowed values
            $allowedActions = ['create', 'update', 'delete', 'force_delete'];
            if (!in_array($operation['action'], $allowedActions, true)) {
                return false;
            }
            
            // Validate entity is in whitelist
            if (!isset($this->models[$operation['entity']])) {
                return false;
            }
            
            // Data must be an array if present
            if (isset($operation['data']) && !is_array($operation['data'])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * SECURITY: Sanitize operation data to prevent XSS and injection attacks.
     */
    private function sanitizeOperationData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            // Only allow alphanumeric, underscore, hyphen keys
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$key)) {
                continue; // Skip potentially malicious keys
            }
            
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeOperationData($value);
            } elseif (is_string($value)) {
                // Remove null bytes and control characters
                $sanitized[$key] = $this->sanitizeString($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * SECURITY: Sanitize a string value to remove dangerous content.
     */
    private function sanitizeString(string $value): string
    {
        // Remove null bytes
        $value = str_replace("\0", '', $value);
        
        // Remove other control characters except newlines and tabs
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        return trim($value);
    }
    
    /**
     * SECURITY: Validate and sanitize UUID format.
     */
    private function sanitizeUuid(string $uuid): ?string
    {
        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        // where y is 8, 9, a, or b
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        
        if (preg_match($pattern, $uuid)) {
            return strtolower($uuid);
        }
        
        // Also accept simple UUID-like strings for compatibility
        if (preg_match('/^[a-zA-Z0-9\-_]{8,36}$/', $uuid)) {
            return $uuid;
        }
        
        return null;
    }

}
