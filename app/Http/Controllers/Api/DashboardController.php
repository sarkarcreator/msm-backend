<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Sale;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $today = Carbon::today();
        $month = Carbon::now()->startOfMonth();

        return [
            'todaySales' => Sale::whereDate('sold_at', $today)->sum('total'),
            'todayProfit' => Sale::whereDate('sold_at', $today)->sum('profit'),
            'monthlySales' => Sale::where('sold_at', '>=', $month)->sum('total'),
            'monthlyProfit' => Sale::where('sold_at', '>=', $month)->sum('profit'),
            'stockValue' => Product::selectRaw('COALESCE(SUM(quantity * purchase_price),0) as value')->value('value'),
            'inventoryItems' => Product::sum('quantity'),
            'lowStock' => Product::whereColumn('quantity', '<=', 'low_stock_threshold')->count(),
            'expenses' => Expense::where('spent_at', '>=', $month)->sum('amount'),
            'recentSales' => Sale::latest('sold_at')->limit(10)->get(),
            'recentExpenses' => Expense::latest('spent_at')->limit(10)->get(),
        ];
    }
}
