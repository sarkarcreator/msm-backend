<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\BarcodeRegistryService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceController extends Controller
{
    private array $licenseScopedResources = [
        'products', 'categories', 'brands', 'customers', 'customer-ledgers', 'suppliers',
        'supplier-ledgers', 'sales', 'sale-items', 'purchases', 'purchase-items',
        'expenses', 'repairs', 'repair-updates', 'payments', 'cashbook', 'users',
        'settings', 'notifications', 'inventory-transactions', 'manual-repair-receipts',
        'mobile-wallet-transactions', 'patients', 'assistants', 'hospital-prescriptions',
        'hospital-orders', 'hospital-tasks', 'lab-reports', 'radiology-reports',
        'hospital-bills', 'hospital-bill-items', 'master-catalogs',
        'imei-registry', 'imei-movements', 'warranty-claims', 'sale-returns',
        'sale-return-items', 'purchase-returns', 'purchase-return-items',
        'trader-companies', 'trader-brands', 'trader-territories', 'trader-routes',
        'trader-salesmen', 'trader-retailers', 'trader-delivery-challans',
        'trader-recoveries', 'trader-salesman-ledgers', 'trader-distributor-ledgers',
    ];

    private array $hospitalOnlyResources = [
        'patients', 'assistants', 'hospital-prescriptions', 'hospital-orders', 'hospital-tasks',
        'lab-reports', 'radiology-reports', 'hospital-bills', 'hospital-bill-items',
    ];

    private array $repairOnlyResources = ['repairs', 'repair-updates', 'manual-repair-receipts'];

    private array $mobileShopOnlyResources = [
        'imei-registry', 'imei-movements', 'warranty-claims', 'sale-returns',
        'sale-return-items', 'purchase-returns', 'purchase-return-items',
    ];

    private array $tradersOnlyResources = [
        'trader-companies', 'trader-brands', 'trader-territories', 'trader-routes',
        'trader-salesmen', 'trader-retailers', 'trader-delivery-challans',
        'trader-recoveries', 'trader-salesman-ledgers', 'trader-distributor-ledgers',
    ];

    private array $models = [
        'products' => \App\Models\Product::class,
        'categories' => \App\Models\Category::class,
        'brands' => \App\Models\Brand::class,
        'customers' => \App\Models\Customer::class,
        'customer-ledgers' => \App\Models\CustomerLedger::class,
        'sales' => \App\Models\Sale::class,
        'sale-items' => \App\Models\SaleItem::class,
        'suppliers' => \App\Models\Supplier::class,
        'supplier-ledgers' => \App\Models\SupplierLedger::class,
        'purchases' => \App\Models\Purchase::class,
        'purchase-items' => \App\Models\PurchaseItem::class,
        'expenses' => \App\Models\Expense::class,
        'payments' => \App\Models\Payment::class,
        'cashbook' => \App\Models\CashbookEntry::class,
        'repairs' => \App\Models\Repair::class,
        'repair-updates' => \App\Models\RepairUpdate::class,
        'inventory-transactions' => \App\Models\InventoryTransaction::class,
        'users' => \App\Models\User::class,
        'roles' => \App\Models\Role::class,
        'permissions' => \App\Models\Permission::class,
        'settings' => \App\Models\Setting::class,
        'notifications' => \App\Models\Notification::class,
        'manual-repair-receipts' => \App\Models\ManualRepairReceipt::class,
        'mobile-wallet-transactions' => \App\Models\MobileWalletTransaction::class,
        'patients' => \App\Models\Patient::class,
        'assistants' => \App\Models\Assistant::class,
        'hospital-prescriptions' => \App\Models\HospitalPrescription::class,
        'hospital-orders' => \App\Models\HospitalOrder::class,
        'hospital-tasks' => \App\Models\HospitalTask::class,
        'lab-reports' => \App\Models\LabReport::class,
        'radiology-reports' => \App\Models\RadiologyReport::class,
        'hospital-bills' => \App\Models\HospitalBill::class,
        'hospital-bill-items' => \App\Models\HospitalBillItem::class,
        'master-catalogs' => \App\Models\MasterCatalog::class,
        'imei-registry' => \App\Models\ImeiRegistry::class,
        'imei-movements' => \App\Models\ImeiMovement::class,
        'warranty-claims' => \App\Models\WarrantyClaim::class,
        'sale-returns' => \App\Models\SaleReturn::class,
        'sale-return-items' => \App\Models\SaleReturnItem::class,
        'purchase-returns' => \App\Models\PurchaseReturn::class,
        'purchase-return-items' => \App\Models\PurchaseReturnItem::class,
        'trader-companies' => \App\Models\TraderCompany::class,
        'trader-brands' => \App\Models\TraderBrand::class,
        'trader-territories' => \App\Models\TraderTerritory::class,
        'trader-routes' => \App\Models\TraderRoute::class,
        'trader-salesmen' => \App\Models\TraderSalesman::class,
        'trader-retailers' => \App\Models\TraderRetailer::class,
        'trader-delivery-challans' => \App\Models\TraderDeliveryChallan::class,
        'trader-recoveries' => \App\Models\TraderRecovery::class,
        'trader-salesman-ledgers' => \App\Models\TraderSalesmanLedger::class,
        'trader-distributor-ledgers' => \App\Models\TraderDistributorLedger::class,
        'licenses' => \App\Models\License::class,
        'audit-logs' => \App\Models\AuditLog::class,
    ];

    public function index(Request $request)
    {
        $this->authorizeAccess($request);
        $query = $this->model($request);
        $resource = explode('.', $request->route()->getName())[0];
        $role = optional($request->user()?->role)->name;

        if ($resource === 'users' && $role === 'Super Admin') {
            $query->whereHas('role', fn ($roleQuery) => $roleQuery->where('name', 'Super Admin'));
        }
        return $query->latest('updated_at')->paginate($request->integer('per_page', 50));
    }

    public function store(Request $request)
    {
        $this->authorizeAccess($request);
        $payload = $this->payload($request);
        $payload['uuid'] ??= (string) Str::uuid();
        $businessUuidColumns = [
            'trader-companies' => 'company_uuid',
            'trader-brands' => 'brand_uuid',
            'trader-territories' => 'territory_uuid',
            'trader-routes' => 'route_uuid',
            'trader-salesmen' => 'salesman_uuid',
            'trader-retailers' => 'retailer_uuid',
            'trader-delivery-challans' => 'challan_uuid',
            'trader-recoveries' => 'recovery_uuid',
        ];
        $resource = explode('.', $request->route()->getName())[0];
        if (isset($businessUuidColumns[$resource]) && blank($payload[$businessUuidColumns[$resource]] ?? null)) {
            $payload[$businessUuidColumns[$resource]] = $payload['uuid'];
        }
        if ($resource === 'products') {
            app(BarcodeRegistryService::class)->validateProductPayload($payload, $request->user(), $payload['uuid'] ?? null);
        }
        try {
            $record = $this->storeRecord($request, $payload);
            $this->audit($request, 'create', $record->uuid, $payload);
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                $this->queryExceptionField($exception) => $this->queryExceptionMessage($exception),
            ]);
        }

        return response($record, 201);
    }

    public function show(Request $request, string $id)
    {
        $this->authorizeAccess($request);
        return $this->model($request)
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('id', $id))
            ->firstOrFail();
    }

    public function update(Request $request, string $id)
    {
        $this->authorizeAccess($request);
        $record = $this->model($request)
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('id', $id))
            ->firstOrFail();
        $payload = $this->payload($request, true);
        $payload = $this->filterPayloadForTable($record->getTable(), $payload);
        $this->guardStatusTransition($record, $payload);
        if (explode('.', $request->route()->getName())[0] === 'products') {
            app(BarcodeRegistryService::class)->validateProductPayload($payload, $request->user(), $record->uuid);
        }
        try {
            $record->update($payload);
            $this->audit($request, 'update', $record->uuid, $payload);
        } catch (QueryException $exception) {
            throw ValidationException::withMessages([
                $this->queryExceptionField($exception) => $this->queryExceptionMessage($exception),
            ]);
        }

        return $record;
    }

    public function destroy(Request $request, string $id)
    {
        $this->authorizeAccess($request);
        $record = $this->model($request)
            ->where(fn ($query) => $query->where('uuid', $id)->orWhere('id', $id))
            ->first();

        if (! $record) {
            return response()->noContent();
        }

        $payload = $record->toArray();
        $uuid = $record->uuid;
        $force = $request->boolean('force');
        $resource = explode('.', $request->route()->getName())[0];

        try {
            DB::transaction(function () use ($request, $record, $resource, $uuid, $payload, $force) {
                $this->deleteBlockingRelations($request, $resource, $record, $force);

                $force ? $record->forceDelete() : $record->delete();
                if ($resource === 'licenses') {
                    $users = \App\Models\User::withTrashed()->where('license_uuid', $uuid)->get();
                    foreach ($users as $user) {
                        $userPayload = $user->toArray();
                        $force ? $user->forceDelete() : $user->delete();
                        $this->queueTombstone($request, 'users', $user->uuid, $userPayload, $force);
                    }
                }
                if ($resource === 'patients') {
                    $this->deletePatientWorkflow($request, $uuid, $force);
                }
                $this->queueTombstone($request, $resource, $uuid, $payload, $force);
                $this->audit($request, $force ? 'permanent_delete' : 'soft_delete', $uuid, $payload);
            });
        } catch (QueryException $exception) {
            Log::error('Resource delete failed', [
                'resource' => $resource,
                'uuid' => $uuid,
                'force' => $force,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'delete' => 'Record cannot be deleted because linked records still exist.',
            ]);
        }

        return response()->noContent();
    }

    public function bulkDestroy(Request $request)
    {
        $this->authorizeAccess($request);
        $resource = explode('.', $request->route()->getName())[0];
        $force = $request->boolean('force');
        $validated = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['required', 'string', 'max:100'],
        ]);
        $ids = collect($validated['uuids'])->filter()->unique()->values();
        $deleted = [];

        DB::transaction(function () use ($request, $resource, $force, $ids, &$deleted) {
            $records = $this->model($request)
                ->where(fn ($query) => $query->whereIn('uuid', $ids)->orWhereIn('id', $ids))
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $payload = $record->toArray();
                $uuid = $record->uuid;
                $this->deleteBlockingRelations($request, $resource, $record, $force);
                $force ? $record->forceDelete() : $record->delete();
                $this->queueTombstone($request, $resource, $uuid, $payload, $force);
                $this->audit($request, $force ? 'bulk_permanent_delete' : 'bulk_soft_delete', $uuid, $payload);
                $deleted[] = $uuid;
            }
        });

        Log::info('Bulk delete completed', [
            'resource' => $resource,
            'deleted_count' => count($deleted),
            'deleted_ids' => $deleted,
            'force' => $force,
            'actor_uuid' => $request->user()?->uuid,
        ]);

        return response()->json([
            'deleted_count' => count($deleted),
            'deleted_ids' => $deleted,
        ]);
    }

    private function deleteBlockingRelations(Request $request, string $resource, $record, bool $force): void
    {
        if ($resource === 'sales') {
            $this->deleteRows($request, 'sale-items', \App\Models\SaleItem::class, [
                'sale_id' => $record->id,
                'sale_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'sale-returns', \App\Models\SaleReturn::class, [
                'sale_id' => $record->id,
                'sale_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'imei-movements', \App\Models\ImeiMovement::class, [
                'sale_id' => $record->id,
                'sale_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'warranty-claims', \App\Models\WarrantyClaim::class, [
                'sale_id' => $record->id,
                'sale_uuid' => $record->uuid,
            ], $force);
            $this->deleteLegacyTableRows('credits', [
                'sale_id' => $record->id,
                'sale_uuid' => $record->uuid,
            ]);
        }

        if ($resource === 'purchases') {
            $this->deleteRows($request, 'purchase-items', \App\Models\PurchaseItem::class, [
                'purchase_id' => $record->id,
                'purchase_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'purchase-returns', \App\Models\PurchaseReturn::class, [
                'purchase_id' => $record->id,
                'purchase_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'supplier-ledgers', \App\Models\SupplierLedger::class, [
                'reference' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'cashbook', \App\Models\CashbookEntry::class, [
                'reference' => $record->uuid,
            ], $force);
        }

        if ($resource === 'customers') {
            $this->deleteRows($request, 'customer-ledgers', \App\Models\CustomerLedger::class, [
                'customer_id' => $record->id,
                'customer_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'payments', \App\Models\Payment::class, [
                'customer_uuid' => $record->uuid,
            ], $force);
            $this->deleteLegacyTableRows('customer_payments', [
                'customer_id' => $record->id,
                'customer_uuid' => $record->uuid,
            ]);
            $this->deleteLegacyTableRows('credits', [
                'customer_id' => $record->id,
                'customer_uuid' => $record->uuid,
            ]);
            $this->nullifyRows($request, 'sales', \App\Models\Sale::class, [
                'customer_id' => $record->id,
                'customer_uuid' => $record->uuid,
            ], ['customer_id', 'customer_uuid']);
        }

        if ($resource === 'suppliers') {
            $this->deleteRows($request, 'supplier-ledgers', \App\Models\SupplierLedger::class, [
                'supplier_id' => $record->id,
                'supplier_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'payments', \App\Models\Payment::class, [
                'supplier_uuid' => $record->uuid,
            ], $force);
            $this->nullifyRows($request, 'purchases', \App\Models\Purchase::class, [
                'supplier_id' => $record->id,
                'supplier_uuid' => $record->uuid,
            ], ['supplier_id', 'supplier_uuid']);
        }

        if ($resource === 'products') {
            $this->deleteRows($request, 'sale-return-items', \App\Models\SaleReturnItem::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'purchase-return-items', \App\Models\PurchaseReturnItem::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'sale-items', \App\Models\SaleItem::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'purchase-items', \App\Models\PurchaseItem::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'inventory-transactions', \App\Models\InventoryTransaction::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'imei-registry', \App\Models\ImeiRegistry::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'imei-movements', \App\Models\ImeiMovement::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteRows($request, 'warranty-claims', \App\Models\WarrantyClaim::class, [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ], $force);
            $this->deleteLegacyTableRows('imei_numbers', [
                'product_id' => $record->id,
                'product_uuid' => $record->uuid,
            ]);
        }
    }

    private function deleteRows(Request $request, string $resource, string $model, array $matches, bool $force): void
    {
        $instance = app($model);
        $table = $instance->getTable();
        if (! Schema::hasTable($table)) {
            return;
        }
        if (! $this->hasAnyMatchColumn($table, $matches)) {
            return;
        }

        $query = $model::withTrashed()->where(function ($where) use ($table, $matches) {
            foreach ($matches as $column => $value) {
                if ($value !== null && Schema::hasColumn($table, $column)) {
                    $where->orWhere($column, $value);
                }
            }
        });

        foreach ($query->get() as $row) {
            $payload = $row->toArray();
            $force ? $row->forceDelete() : $row->delete();
            if (! empty($row->uuid)) {
                $this->queueTombstone($request, $resource, $row->uuid, $payload, $force);
            }
        }
    }

    private function nullifyRows(Request $request, string $resource, string $model, array $matches, array $columns): void
    {
        $instance = app($model);
        $table = $instance->getTable();
        if (! Schema::hasTable($table) || ! $this->hasAnyMatchColumn($table, $matches)) {
            return;
        }

        $availableColumns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
        if (! count($availableColumns)) {
            return;
        }

        $query = $model::withTrashed()->where(function ($where) use ($table, $matches) {
            foreach ($matches as $column => $value) {
                if ($value !== null && Schema::hasColumn($table, $column)) {
                    $where->orWhere($column, $value);
                }
            }
        });

        foreach ($query->get() as $row) {
            $payload = $row->toArray();
            foreach ($availableColumns as $column) {
                $row->{$column} = null;
                $payload[$column] = null;
            }
            $row->save();
            if (! empty($row->uuid)) {
                $this->queueResourceUpdate($request, $resource, $row->uuid, $row->fresh()->toArray());
                $this->audit($request, 'detach_deleted_parent', $row->uuid, $payload);
            }
        }
    }

    private function deleteLegacyTableRows(string $table, array $matches): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (! $this->hasAnyMatchColumn($table, $matches)) {
            return;
        }

        $query = DB::table($table)->where(function ($where) use ($table, $matches) {
            foreach ($matches as $column => $value) {
                if ($value !== null && Schema::hasColumn($table, $column)) {
                    $where->orWhere($column, $value);
                }
            }
        });

        $query->delete();
    }

    private function hasAnyMatchColumn(string $table, array $matches): bool
    {
        foreach ($matches as $column => $value) {
            if ($value !== null && Schema::hasColumn($table, $column)) {
                return true;
            }
        }

        return false;
    }

    private function deletePatientWorkflow(Request $request, string $patientUuid, bool $force): void
    {
        foreach ([
            'hospital_prescriptions' => \App\Models\HospitalPrescription::class,
            'hospital_orders' => \App\Models\HospitalOrder::class,
            'hospital_tasks' => \App\Models\HospitalTask::class,
            'lab_reports' => \App\Models\LabReport::class,
            'radiology_reports' => \App\Models\RadiologyReport::class,
            'hospital_bills' => \App\Models\HospitalBill::class,
            'hospital_bill_items' => \App\Models\HospitalBillItem::class,
        ] as $entity => $model) {
            $rows = $model::withTrashed()->where('patient_uuid', $patientUuid)->get();
            foreach ($rows as $row) {
                $payload = $row->toArray();
                $force ? $row->forceDelete() : $row->delete();
                $this->queueTombstone($request, str_replace('_', '-', $entity), $row->uuid, $payload, $force);
            }
        }
    }

    private function model(Request $request)
    {
        $key = $request->route()->getName();
        $resource = explode('.', $key)[0];

        abort_unless(isset($this->models[$resource]), 404);

        $query = app($this->models[$resource])->newQuery();
        $role = optional($request->user()?->role)->name;

        if (Schema::hasColumn(app($this->models[$resource])->getTable(), 'quarantined_at')) {
            $query->whereNull('quarantined_at');
        }

        if ($role !== 'Super Admin' && $this->hasLicenseColumn($resource)) {
            abort_unless($request->user()?->license_uuid, 403, 'Tenant scope is required.');
            $query->where('license_uuid', $request->user()->license_uuid);
        }

        return $query;
    }

    private function audit(Request $request, string $action, string $uuid, array $payload): void
    {
        $resource = explode('.', $request->route()->getName())[0];

        if ($resource === 'audit-logs') {
            return;
        }

        try {
            \App\Models\AuditLog::create($this->filterPayloadForTable('audit_logs', [
                'uuid' => (string) Str::uuid(),
                'license_uuid' => $payload['license_uuid'] ?? $request->user()?->license_uuid,
                'business_type' => $payload['business_type'] ?? $request->user()?->business_type,
                'user_name' => optional($request->user())->name ?: 'API User',
                'action' => $action,
                'entity' => $resource,
                'entity_uuid' => $uuid,
                'details' => "{$action} {$resource}",
                'metadata' => $payload,
            ]));
        } catch (\Throwable $exception) {
            Log::warning('Audit log write skipped', ['resource' => $resource, 'error' => $exception->getMessage()]);
        }
    }

    private function storeRecord(Request $request, array $payload)
    {
        $resource = explode('.', $request->route()->getName())[0];
        $query = $this->model($request);

        $record = $query->where('uuid', $payload['uuid'])->first();
        if (! $record && $resource === 'users' && ! empty($payload['email'])) {
            $record = $query->where('email', $payload['email'])->first();
        }
        if (! $record && $resource === 'licenses') {
            $record = $query->orWhere('license_key', $payload['license_key'] ?? '')->first();
        }

        if ($record) {
            $record->update($this->filterPayloadForTable($record->getTable(), $payload));
            return $record->fresh();
        }

        $table = app($this->models[$resource])->getTable();

        return $query->create($this->filterPayloadForTable($table, $payload));
    }

    private function filterPayloadForTable(string $table, array $payload): array
    {
        if (! Schema::hasTable($table)) {
            return $payload;
        }

        return collect($payload)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->all();
    }

    private function queryExceptionField(QueryException $exception): string
    {
        $message = $exception->getMessage();
        if (preg_match("/Column not found:.*Unknown column '([^']+)'/i", $message, $match)) {
            return $match[1];
        }
        if (str_contains($message, 'users_email_unique') || str_contains($message, "Duplicate entry")) {
            return 'email';
        }
        return 'database';
    }

    private function queryExceptionMessage(QueryException $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'users_email_unique') || str_contains($message, "Duplicate entry")) {
            return 'This email is already assigned to another user.';
        }
        if (preg_match("/Column not found:.*Unknown column '([^']+)'/i", $message, $match)) {
            return "Database column {$match[1]} is missing. Run php artisan migrate --force on the backend.";
        }
        if (preg_match("/Field '([^']+)' doesn't have a default value/i", $message, $match)) {
            return "Required database field {$match[1]} is missing from this request.";
        }
        Log::error('Resource database write failed', ['error' => $message]);
        return 'Database write failed. Please check backend migrations and logs.';
    }

    private function authorizeAccess(Request $request): void
    {
        $resource = explode('.', $request->route()->getName())[0];
        $role = optional($request->user()?->role)->name ?: 'Cashier';

        $allowed = match ($role) {
            'Super Admin' => array_keys($this->models),
            'Admin' => [
                'products', 'categories', 'brands', 'customers', 'customer-ledgers',
                'sales', 'sale-items', 'suppliers', 'supplier-ledgers', 'purchases',
                'purchase-items', 'expenses', 'payments', 'cashbook', 'repairs',
                'repair-updates', 'inventory-transactions', 'users', 'roles',
                'notifications', 'manual-repair-receipts', 'mobile-wallet-transactions',
                'patients', 'assistants', 'hospital-prescriptions', 'hospital-orders',
                'hospital-tasks', 'lab-reports', 'radiology-reports', 'hospital-bills',
                'hospital-bill-items', 'master-catalogs', 'imei-registry',
                'imei-movements', 'warranty-claims', 'sale-returns', 'sale-return-items',
                'purchase-returns', 'purchase-return-items',
                'trader-companies', 'trader-brands', 'trader-territories', 'trader-routes',
                'trader-salesmen', 'trader-retailers', 'trader-delivery-challans',
                'trader-recoveries', 'trader-salesman-ledgers', 'trader-distributor-ledgers',
            ],
            'Manager' => [
                'products', 'categories', 'brands', 'customers', 'customer-ledgers',
                'sales', 'sale-items', 'suppliers', 'supplier-ledgers', 'purchases',
                'purchase-items', 'expenses', 'payments', 'cashbook', 'repairs',
                'repair-updates', 'inventory-transactions', 'notifications',
                'manual-repair-receipts', 'mobile-wallet-transactions', 'patients', 'assistants',
                'hospital-prescriptions', 'hospital-orders', 'hospital-tasks', 'lab-reports',
                'radiology-reports', 'hospital-bills', 'hospital-bill-items', 'master-catalogs',
                'imei-registry', 'imei-movements', 'warranty-claims', 'sale-returns',
                'sale-return-items', 'purchase-returns', 'purchase-return-items',
                'trader-companies', 'trader-brands', 'trader-territories', 'trader-routes',
                'trader-salesmen', 'trader-retailers', 'trader-delivery-challans',
                'trader-recoveries', 'trader-salesman-ledgers', 'trader-distributor-ledgers',
            ],
            'Technician' => ['customers', 'repairs', 'repair-updates', 'manual-repair-receipts', 'patients', 'notifications', 'master-catalogs'],
            'Hospital Owner' => ['patients', 'assistants', 'users', 'expenses', 'cashbook', 'notifications', 'hospital-prescriptions', 'hospital-orders', 'hospital-tasks', 'lab-reports', 'radiology-reports', 'hospital-bills', 'hospital-bill-items', 'master-catalogs'],
            'Receptionist' => ['patients', 'hospital-bills', 'hospital-bill-items', 'hospital-tasks', 'lab-reports', 'radiology-reports', 'notifications', 'master-catalogs'],
            'Doctor' => ['patients', 'assistants', 'expenses', 'cashbook', 'notifications', 'hospital-prescriptions', 'hospital-orders', 'hospital-tasks', 'lab-reports', 'radiology-reports', 'hospital-bills', 'hospital-bill-items', 'master-catalogs'],
            'Compounder' => ['patients', 'hospital-tasks', 'hospital-prescriptions', 'notifications', 'master-catalogs'],
            'Assistant' => ['patients', 'hospital-tasks', 'hospital-prescriptions', 'notifications', 'master-catalogs'],
            'Nurse' => ['patients', 'hospital-tasks', 'notifications'],
            'Pharmacy Staff' => ['hospital-prescriptions', 'products', 'inventory-transactions', 'notifications', 'medicines'],
            'Lab Technician' => ['lab-reports', 'hospital-tasks', 'notifications'],
            'X-Ray Technician' => ['radiology-reports', 'hospital-tasks', 'notifications'],
            'Billing Officer' => ['hospital-bills', 'hospital-bill-items', 'hospital-tasks', 'lab-reports', 'radiology-reports', 'patients', 'notifications'],
            'Cashier' => ['customers', 'customer-ledgers', 'sales', 'sale-items', 'payments', 'cashbook', 'mobile-wallet-transactions', 'manual-repair-receipts', 'notifications', 'master-catalogs', 'imei-registry', 'warranty-claims', 'sale-returns', 'sale-return-items', 'trader-retailers', 'trader-recoveries'],
            default => ['customers', 'customer-ledgers', 'sales', 'sale-items', 'payments', 'cashbook', 'manual-repair-receipts', 'mobile-wallet-transactions', 'patients', 'notifications', 'master-catalogs'],
        };

        abort_unless(in_array($resource, $allowed, true), 403, 'You do not have permission to access this module.');

        if ($role !== 'Super Admin') {
            $businessType = $request->user()?->business_type ?: 'General Store';
            abort_unless($this->businessTypeAllowsResource($businessType, $resource), 403, 'This module is not available for this business type.');
        }
    }

    private function businessTypeAllowsResource(string $businessType, string $resource): bool
    {
        $key = strtolower(str_replace([' ', '-'], '_', trim($businessType)));
        $key = match ($key) {
            'mobile', 'mobile_shop' => 'mobile_shop',
            'hospital' => 'hospital',
            'pharmacy' => 'pharmacy',
            'traders' => 'traders',
            'electronics_store' => 'electronics_store',
            'general_store', 'grocery_store', 'grocery', 'shopping_mall', 'retail_shop' => 'general_store',
            default => 'generic_shop',
        };

        if ($key !== 'hospital' && in_array($resource, $this->hospitalOnlyResources, true)) {
            return false;
        }

        if ($key !== 'mobile_shop' && in_array($resource, $this->mobileShopOnlyResources, true)) {
            return false;
        }

        if (! in_array($key, ['mobile_shop', 'electronics_store'], true) && in_array($resource, $this->repairOnlyResources, true)) {
            return false;
        }

        if ($key !== 'traders' && in_array($resource, $this->tradersOnlyResources, true)) {
            return false;
        }

        return true;
    }

    private function payload(Request $request, bool $updating = false): array
    {
        $resource = explode('.', $request->route()->getName())[0];
        $payload = $request->except(['id', 'created_at', 'updated_at', 'deleted_at', 'sync_status']);

        if ($resource === 'licenses') {
            $payload = array_intersect_key($payload, array_flip([
                'uuid', 'license_key', 'activation_code', 'owner_name', 'business_type', 'device_id',
                'type', 'status', 'trial', 'expiry_date', 'activated_at', 'metadata',
            ]));

            if (! empty($payload['activated_at'])) {
                $payload['activated_at'] = Carbon::parse($payload['activated_at'])->format('Y-m-d H:i:s');
            }

            return $payload;
        }

        if ($resource !== 'users') {
            if ($request->user()?->license_uuid && $this->hasLicenseColumn($resource)) {
                $payload['license_uuid'] = $request->user()->license_uuid;
            }
            if ($request->user()?->business_type && $this->hasLicenseColumn($resource)) {
                $payload['business_type'] = $request->user()->business_type;
            }
            if ($resource === 'patients' && blank($payload['token_number'] ?? null)) {
                $payload['token_number'] = $this->nextPatientToken($request);
            }
            if ($resource === 'master-catalogs') {
                $payload = $this->normalizeMasterCatalogPayload($payload);
            }
            return $payload;
        }

        unset($payload['role'], $payload['status']);

        if ($request->filled('role')) {
            $requestedRole = $request->string('role')->toString();
            $allowedRoles = $this->assignableRoles($request);

            abort_unless(in_array($requestedRole, $allowedRoles, true), 403, 'You cannot assign this user role.');

            $role = Role::firstOrCreate(
                ['name' => $requestedRole],
                ['uuid' => (string) Str::uuid()]
            );
            $payload['role_id'] = $role->id;
        }

        $actor = $request->user();
        $actorRole = optional($actor?->role)->name;

        if ($actorRole !== 'Super Admin') {
            $payload['license_uuid'] = $actor?->license_uuid;
            $payload['business_type'] = $actor?->business_type ?: ($payload['business_type'] ?? 'General Store');
            $payload['shop_name'] = $actor?->shop_name ?: ($payload['shop_name'] ?? 'Retail Shop');
        } else {
            $payload['business_type'] = $payload['business_type'] ?? $actor?->business_type;
            $payload['shop_name'] = $payload['shop_name'] ?? $actor?->shop_name;
        }

        if ($updating && blank($request->input('password'))) {
            unset($payload['password']);
        }

        return $payload;
    }

    private function normalizeMasterCatalogPayload(array $payload): array
    {
        $name = $payload['product_name'] ?? $payload['name'] ?? null;
        if ($name) {
            $payload['name'] = $name;
            $payload['product_name'] = $name;
        }

        $payload['default_cost'] = $this->nonNegativeMoney(
            $payload['default_cost'] ?? $payload['cost_price'] ?? $payload['purchase_price'] ?? $payload['unit_cost_price'] ?? 0,
            'default_cost'
        );
        $payload['default_price'] = $this->nonNegativeMoney(
            $payload['default_price'] ?? $payload['selling_price'] ?? $payload['sale_price'] ?? $payload['unit_sale_price'] ?? 0,
            'default_price'
        );

        unset(
            $payload['cost_price'],
            $payload['purchase_price'],
            $payload['unit_cost_price'],
            $payload['selling_price'],
            $payload['sale_price'],
            $payload['unit_sale_price']
        );

        return $payload;
    }

    private function nonNegativeMoney(mixed $value, string $field): float
    {
        $number = (float) ($value ?? 0);
        if ($number < 0) {
            throw ValidationException::withMessages([$field => 'Price values cannot be negative.']);
        }

        return $number;
    }

    private function assignableRoles(Request $request): array
    {
        $actorRole = optional($request->user()?->role)->name ?: 'Cashier';

        if ($actorRole === 'Super Admin') {
            return ['Super Admin', 'Admin', 'Manager', 'Cashier', 'Technician', 'Doctor', 'Compounder', 'Assistant'];
        }

        $businessType = $request->user()?->business_type ?: 'General Store';

        if ($businessType === 'Hospital') {
            return ['Hospital Owner', 'Admin', 'Receptionist', 'Doctor', 'Assistant', 'Compounder', 'Nurse', 'Pharmacy Staff', 'Lab Technician', 'X-Ray Technician', 'Billing Officer', 'Manager'];
        }

        if ($businessType === 'Mobile Shop') {
            return ['Manager', 'Cashier', 'Technician'];
        }

        return ['Manager', 'Cashier'];
    }

    private function hasLicenseColumn(string $resource): bool
    {
        if (! in_array($resource, $this->licenseScopedResources, true) || ! isset($this->models[$resource])) {
            return false;
        }

        $table = app($this->models[$resource])->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, 'license_uuid');
    }

    private function guardStatusTransition($record, array $payload): void
    {
        if (! $record instanceof \App\Models\Patient || ! array_key_exists('status', $payload)) {
            return;
        }

        $flow = [
            'Waiting' => ['Doctor Checked'],
            'Doctor Checked' => ['Sent To Reception'],
            'Sent To Reception' => ['Under Treatment'],
            'Under Treatment' => ['Treatment Completed'],
            'Treatment Completed' => ['Closed'],
            'Closed' => [],
        ];
        $current = $record->status ?: 'Waiting';
        $next = $payload['status'];

        if ($current !== $next && ! in_array($next, $flow[$current] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Invalid status transition from {$current} to {$next}.",
            ]);
        }
    }

    private function queueTombstone(Request $request, string $resource, string $uuid, array $payload, bool $force): void
    {
        $entity = str_replace('-', '_', $resource);
        $row = [
            'uuid' => $uuid,
            'device_id' => $request->header('X-Device-Id', 'api'),
            'entity' => $entity,
            'action' => $force ? 'force_delete' : 'delete',
            'payload' => $payload,
            'synced_at' => now(),
        ];

        if (Schema::hasColumn('sync_queue', 'license_uuid')) {
            $row['license_uuid'] = $payload['license_uuid'] ?? $request->user()?->license_uuid;
        }
        if (Schema::hasColumn('sync_queue', 'business_type')) {
            $row['business_type'] = $payload['business_type'] ?? $request->user()?->business_type;
        }
        if (Schema::hasColumn('sync_queue', 'record_updated_at')) {
            $row['record_updated_at'] = now();
        }
        if (Schema::hasColumn('sync_queue', 'is_tombstone')) {
            $row['is_tombstone'] = true;
        }

        \App\Models\SyncQueue::create($row);
    }

    private function queueResourceUpdate(Request $request, string $resource, string $uuid, array $payload): void
    {
        $row = [
            'uuid' => (string) Str::uuid(),
            'device_id' => $request->header('X-Device-Id', 'api'),
            'entity' => str_replace('-', '_', $resource),
            'action' => 'update',
            'payload' => $payload,
            'synced_at' => now(),
        ];

        if (Schema::hasColumn('sync_queue', 'license_uuid')) {
            $row['license_uuid'] = $payload['license_uuid'] ?? $request->user()?->license_uuid;
        }
        if (Schema::hasColumn('sync_queue', 'business_type')) {
            $row['business_type'] = $payload['business_type'] ?? $request->user()?->business_type;
        }
        if (Schema::hasColumn('sync_queue', 'record_updated_at')) {
            $row['record_updated_at'] = now();
        }
        if (Schema::hasColumn('sync_queue', 'is_tombstone')) {
            $row['is_tombstone'] = false;
        }

        \App\Models\SyncQueue::create($row);
    }

    private function nextPatientToken(Request $request): string
    {
        $licenseUuid = $request->user()?->license_uuid;
        abort_unless($licenseUuid, 403, 'Tenant scope is required.');

        $last = \App\Models\Patient::withTrashed()
            ->where('license_uuid', $licenseUuid)
            ->where('token_number', 'like', 'T-%')
            ->orderByDesc('id')
            ->value('token_number');

        $number = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1;

        do {
            $token = 'T-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $exists = \App\Models\Patient::withTrashed()
                ->where('license_uuid', $licenseUuid)
                ->where('token_number', $token)
                ->exists();
            $number++;
        } while ($exists);

        return $token;
    }
}
