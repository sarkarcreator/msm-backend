<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    ];

    private array $hospitalOnlyResources = [
        'patients', 'assistants', 'hospital-prescriptions', 'hospital-orders', 'hospital-tasks',
        'lab-reports', 'radiology-reports', 'hospital-bills', 'hospital-bill-items',
    ];

    private array $repairOnlyResources = ['repairs', 'repair-updates', 'manual-repair-receipts'];

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
        $record = $this->storeRecord($request, $payload);
        $this->audit($request, 'create', $record->uuid, $payload);

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
        $record->update($payload);
        $this->audit($request, 'update', $record->uuid, $payload);

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

        $force ? $record->forceDelete() : $record->delete();
        if ($resource === 'licenses') {
            $users = \App\Models\User::withTrashed()->where('license_uuid', $uuid)->get();
            foreach ($users as $user) {
                $force ? $user->forceDelete() : $user->delete();
            }
        }
        if ($resource === 'patients') {
            $this->deletePatientWorkflow($uuid, $force);
        }
        $this->audit($request, $force ? 'permanent_delete' : 'soft_delete', $uuid, $payload);

        return response()->noContent();
    }

    private function deletePatientWorkflow(string $patientUuid, bool $force): void
    {
        foreach ([
            \App\Models\HospitalPrescription::class,
            \App\Models\HospitalOrder::class,
            \App\Models\HospitalTask::class,
            \App\Models\LabReport::class,
            \App\Models\RadiologyReport::class,
            \App\Models\HospitalBill::class,
            \App\Models\HospitalBillItem::class,
        ] as $model) {
            $rows = $model::withTrashed()->where('patient_uuid', $patientUuid)->get();
            foreach ($rows as $row) {
                $force ? $row->forceDelete() : $row->delete();
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

        if ($role !== 'Super Admin' && $request->user()?->license_uuid && $this->hasLicenseColumn($resource)) {
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

        \App\Models\AuditLog::create([
            'uuid' => (string) Str::uuid(),
            'user_name' => optional($request->user())->name ?: 'API User',
            'action' => $action,
            'entity' => $resource,
            'entity_uuid' => $uuid,
            'details' => "{$action} {$resource}",
            'metadata' => $payload,
        ]);
    }

    private function storeRecord(Request $request, array $payload)
    {
        $resource = explode('.', $request->route()->getName())[0];
        $query = $this->model($request);

        $record = $query->where('uuid', $payload['uuid'])->first();
        if (! $record && $resource === 'licenses') {
            $record = $query->orWhere('license_key', $payload['license_key'] ?? '')->first();
        }

        if ($record) {
            $record->update($payload);
            return $record->fresh();
        }

        return $query->create($payload);
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
                'hospital-bill-items', 'master-catalogs',
            ],
            'Manager' => [
                'products', 'categories', 'brands', 'customers', 'customer-ledgers',
                'sales', 'sale-items', 'suppliers', 'supplier-ledgers', 'purchases',
                'purchase-items', 'expenses', 'payments', 'cashbook', 'repairs',
                'repair-updates', 'inventory-transactions', 'notifications',
                'manual-repair-receipts', 'mobile-wallet-transactions', 'patients', 'assistants',
                'hospital-prescriptions', 'hospital-orders', 'hospital-tasks', 'lab-reports',
                'radiology-reports', 'hospital-bills', 'hospital-bill-items', 'master-catalogs',
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
            'Cashier' => ['customers', 'customer-ledgers', 'sales', 'sale-items', 'payments', 'cashbook', 'mobile-wallet-transactions', 'manual-repair-receipts', 'notifications', 'master-catalogs'],
            default => ['customers', 'customer-ledgers', 'sales', 'sale-items', 'payments', 'cashbook', 'manual-repair-receipts', 'mobile-wallet-transactions', 'patients', 'notifications', 'master-catalogs'],
        };

        abort_unless(in_array($resource, $allowed, true), 403, 'You do not have permission to access this module.');

        if ($role !== 'Super Admin') {
            $businessType = $request->user()?->business_type ?: 'General Store';
            if ($businessType !== 'Hospital') {
                abort_if(in_array($resource, $this->hospitalOnlyResources, true), 403, 'This module is only available for hospital licenses.');
            }

            if (! in_array($businessType, ['Mobile Shop', 'Electronics Store'], true)) {
                abort_if(in_array($resource, $this->repairOnlyResources, true), 403, 'This module is only available for repair-enabled licenses.');
            }
        }
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
        return in_array($resource, $this->licenseScopedResources, true);
    }
}
