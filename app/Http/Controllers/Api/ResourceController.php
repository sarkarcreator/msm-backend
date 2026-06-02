<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        'licenses' => \App\Models\License::class,
        'audit-logs' => \App\Models\AuditLog::class,
    ];

    public function index(Request $request)
    {
        return $this->model($request)->latest('updated_at')->paginate($request->integer('per_page', 50));
    }

    public function store(Request $request)
    {
        $payload = $request->all();
        $payload['uuid'] ??= (string) Str::uuid();
        $record = $this->model($request)->create($payload);
        $this->audit($request, 'create', $record->uuid, $payload);

        return response($record, 201);
    }

    public function show(Request $request, string $id)
    {
        return $this->model($request)->where('uuid', $id)->orWhere('id', $id)->firstOrFail();
    }

    public function update(Request $request, string $id)
    {
        $record = $this->model($request)->where('uuid', $id)->orWhere('id', $id)->firstOrFail();
        $record->update($request->all());
        $this->audit($request, 'update', $record->uuid, $request->all());

        return $record;
    }

    public function destroy(Request $request, string $id)
    {
        $record = $this->model($request)->where('uuid', $id)->orWhere('id', $id)->firstOrFail();
        $request->boolean('force') ? $record->forceDelete() : $record->delete();
        $this->audit($request, $request->boolean('force') ? 'permanent_delete' : 'soft_delete', $record->uuid, $record->toArray());

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
}
