<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $today = Carbon::today();
        $month = Carbon::now()->startOfMonth();
        $sales = $this->tenantQuery(Sale::query(), 'sales', $request);
        $products = $this->tenantQuery(Product::query(), 'products', $request);
        $expenses = $this->tenantQuery(Expense::query(), 'expenses', $request);

        return [
            'todaySales' => (clone $sales)->whereDate('sold_at', $today)->sum('total'),
            'todayProfit' => (clone $sales)->whereDate('sold_at', $today)->sum('profit'),
            'monthlySales' => (clone $sales)->where('sold_at', '>=', $month)->sum('total'),
            'monthlyProfit' => (clone $sales)->where('sold_at', '>=', $month)->sum('profit'),
            'stockValue' => (clone $products)->selectRaw('COALESCE(SUM(quantity * purchase_price),0) as value')->value('value'),
            'inventoryItems' => (clone $products)->sum('quantity'),
            'lowStock' => (clone $products)->whereColumn('quantity', '<=', 'low_stock_threshold')->count(),
            'expenses' => (clone $expenses)->where('spent_at', '>=', $month)->sum('amount'),
            'recentSales' => (clone $sales)->latest('sold_at')->limit(10)->get(),
            'recentExpenses' => (clone $expenses)->latest('spent_at')->limit(10)->get(),
        ];
    }

    private function tenantQuery($query, string $table, Request $request)
    {
        $user = $request->user();
        $role = optional($user?->role)->name;

        if (Schema::hasColumn($table, 'quarantined_at')) {
            $query->whereNull('quarantined_at');
        }

        if ($role !== 'Super Admin' && Schema::hasColumn($table, 'license_uuid')) {
            abort_unless($user?->license_uuid, 403, 'Tenant scope is required.');
            $query->where('license_uuid', $user->license_uuid);
        }

        return $query;
    }
}
