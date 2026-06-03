<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResourceController extends Controller
{
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
        'master-catalogs' => \App\Models\MasterCatalog::class,
        'licenses' => \App\Models\License::class,
        'audit-logs' => \App\Models\AuditLog::class,
    ];

    public function index(Request $request)
    {
        $this->authorizeAccess($request);
        return $this->model($request)->latest('updated_at')->paginate($request->integer('per_page', 50));
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
        return $this->model($request)->where('uuid', $id)->orWhere('id', $id)->firstOrFail();
    }

    public function update(Request $request, string $id)
    {
        $this->authorizeAccess($request);
        $record = $this->model($request)->where('uuid', $id)->orWhere('id', $id)->firstOrFail();
        $payload = $this->payload($request, true);
        $record->update($payload);
        $this->audit($request, 'update', $record->uuid, $payload);

        return $record;
    }

    public function destroy(Request $request, string $id)
    {
        $this->authorizeAccess($request);
        $record = $this->model($request)->where('uuid', $id)->orWhere('id', $id)->first();

        if (! $record) {
            return response()->noContent();
        }

        $payload = $record->toArray();
        $uuid = $record->uuid;
        $force = $request->boolean('force');

        $force ? $record->forceDelete() : $record->delete();
        $this->audit($request, $force ? 'permanent_delete' : 'soft_delete', $uuid, $payload);

        return response()->noContent();
    }

    private function model(Request $request)
    {
        $key = $request->route()->getName();
        $resource = explode('.', $key)[0];

        abort_unless(isset($this->models[$resource]), 404);

        return app($this->models[$resource])->newQuery();
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

        if ($resource === 'licenses') {
            $record = $query
                ->where('uuid', $payload['uuid'])
                ->orWhere('license_key', $payload['license_key'] ?? '')
                ->first();

            if ($record) {
                $record->update($payload);
                return $record->fresh();
            }
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
                'patients', 'assistants', 'master-catalogs',
            ],
            'Manager' => [
                'products', 'categories', 'brands', 'customers', 'customer-ledgers',
                'sales', 'sale-items', 'suppliers', 'supplier-ledgers', 'purchases',
                'purchase-items', 'expenses', 'payments', 'cashbook', 'repairs',
                'repair-updates', 'inventory-transactions', 'notifications',
                'manual-repair-receipts', 'mobile-wallet-transactions', 'patients', 'assistants', 'master-catalogs',
            ],
            'Technician' => ['customers', 'repairs', 'repair-updates', 'manual-repair-receipts', 'patients', 'notifications', 'master-catalogs'],
            'Doctor' => ['patients', 'assistants', 'expenses', 'cashbook', 'notifications', 'master-catalogs'],
            'Compounder' => ['patients', 'notifications', 'master-catalogs'],
            'Assistant' => ['patients', 'notifications', 'master-catalogs'],
            'Cashier' => ['customers', 'customer-ledgers', 'sales', 'sale-items', 'payments', 'cashbook', 'mobile-wallet-transactions', 'manual-repair-receipts', 'notifications', 'master-catalogs'],
            default => ['customers', 'customer-ledgers', 'sales', 'sale-items', 'payments', 'cashbook', 'manual-repair-receipts', 'mobile-wallet-transactions', 'patients', 'notifications', 'master-catalogs'],
        };

        abort_unless(in_array($resource, $allowed, true), 403, 'You do not have permission to access this module.');
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
            return ['Doctor', 'Compounder', 'Assistant', 'Manager'];
        }

        if ($businessType === 'Mobile Shop') {
            return ['Manager', 'Cashier', 'Technician'];
        }

        return ['Manager', 'Cashier'];
    }
}
