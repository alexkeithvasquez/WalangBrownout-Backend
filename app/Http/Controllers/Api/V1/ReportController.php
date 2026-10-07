<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryDiscrepancy;
use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole('manager', 'admin'), 403, 'Manager access required.');

        $products = Product::where('is_active', true)->get();
        $holdingCost = $products->sum(fn (Product $product) => (float) $product->unit_price * $product->stock_quantity);
        $pendingSpend = PurchaseOrder::where('status', 'Pending')->sum('estimated_cost');
        $monthSales = Order::whereIn('status', ['Processing', 'In Transit', 'Delivered'])
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('total');
        $monthOrderCount = Order::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $openDiscrepancies = InventoryDiscrepancy::where('status', 'Investigating')->count();

        return response()->json(['data' => [
            'holding_cost' => round($holdingCost, 2),
            'pending_purchase_commitment' => round((float) $pendingSpend, 2),
            'sales_month_to_date' => round((float) $monthSales, 2),
            'orders_month_to_date' => $monthOrderCount,
            'active_skus' => $products->count(),
            'below_reorder_point' => $products->filter(fn (Product $p) => $p->stock_quantity <= $p->reorder_point)->count(),
            'open_discrepancies' => $openDiscrepancies,
            'inventory_by_category' => $products->groupBy('category')->map(fn ($group) => [
                'sku_count' => $group->count(),
                'units' => $group->sum('stock_quantity'),
                'holding_cost' => round($group->sum(fn (Product $p) => (float) $p->unit_price * $p->stock_quantity), 2),
            ]),
        ]]);
    }

    public function movements(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole('manager', 'admin'), 403, 'Manager access required.');
        $movements = DB::table('inventory_movements')
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_movements.user_id')
            ->select('inventory_movements.id', 'products.sku', 'products.name', 'inventory_movements.type',
                'inventory_movements.quantity', 'inventory_movements.reference', 'inventory_movements.notes',
                'users.name as actor', 'inventory_movements.created_at')
            ->latest('inventory_movements.created_at')->limit(200)->get();

        return response()->json(['data' => $movements]);
    }
}
