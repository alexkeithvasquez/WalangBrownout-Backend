<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $orders = PurchaseOrder::with('product')->latest()->limit(250)->get();

        return response()->json(['data' => $orders->map(fn (PurchaseOrder $order) => $this->data($order))]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $pendingProductIds = PurchaseOrder::where('status', 'Pending')->pluck('product_id')->all();
        $alerts = Product::where('is_active', true)->orderBy('stock_quantity')->get()->map(function (Product $product) use ($pendingProductIds): array {
            $pending = in_array($product->id, $pendingProductIds, true);
            return [
                'sku' => $product->sku, 'name' => $product->name, 'category' => $product->category,
                'available' => $product->stock_quantity, 'rop' => $product->reorder_point,
                'status' => $pending ? 'ordered' : ($product->stock_quantity <= $product->reorder_point ? 'reorder' : 'ok'),
            ];
        });

        return response()->json(['data' => $alerts]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'sku' => ['required', 'string', 'exists:products,sku'],
            'qty' => ['required', 'integer', 'min:1'],
        ]);
        $order = DB::transaction(function () use ($request, $data): PurchaseOrder {
            $product = Product::where('sku', $data['sku'])->lockForUpdate()->firstOrFail();
            $quantity = (int) $data['qty'];
            return PurchaseOrder::create([
                'code' => 'PO-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3))),
                'product_id' => $product->id,
                'quantity' => $quantity,
                'estimated_cost' => (float) $product->unit_price * $quantity,
                'status' => 'Pending',
                'created_by' => $request->user()->id,
            ])->load('product');
        });

        return response()->json(['data' => $this->data($order)], 201);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'qty' => ['sometimes', 'integer', 'min:1'],
            'batch_code' => ['nullable', 'string', 'max:100'],
            'expiry' => ['nullable', 'date'],
            'bin' => ['nullable', 'string', 'max:100'],
        ]);
        $order = DB::transaction(function () use ($request, $purchaseOrder, $data): PurchaseOrder {
            $order = PurchaseOrder::with('product')->lockForUpdate()->findOrFail($purchaseOrder->id);
            abort_if($order->status !== 'Pending', 409, 'Only pending purchase orders can be received.');
            $product = Product::lockForUpdate()->findOrFail($order->product_id);
            $qty = (int) ($data['qty'] ?? $order->quantity);
            $batchCode = $data['batch_code'] ?? 'LOT-'.strtoupper(bin2hex(random_bytes(3)));
            $batch = $product->batches()->create([
                'batch_code' => $batchCode,
                'quantity_received' => $qty,
                'quantity_remaining' => $qty,
                'received_at' => now(),
                'expiry_date' => $data['expiry'] ?? null,
                'bin_location' => $data['bin'] ?? $product->bin_location,
            ]);
            $product->increment('stock_quantity', $qty);
            \App\Models\InventoryMovement::create([
                'product_id' => $product->id, 'inventory_batch_id' => $batch->id,
                'user_id' => $request->user()->id, 'type' => 'receipt', 'quantity' => $qty,
                'reference' => $order->code,
            ]);
            $order->update(['status' => 'Received', 'received_at' => now()]);

            return $order->fresh('product');
        });

        return response()->json(['data' => $this->data($order)]);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole('staff', 'manager', 'admin'), 403, 'Staff access required.');
    }

    private function data(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id, 'code' => $order->code,
            'sku' => $order->product?->sku, 'name' => $order->product?->name,
            'qty' => $order->quantity, 'estCost' => (float) $order->estimated_cost,
            'status' => $order->status, 'createdAt' => $order->created_at?->valueOf(),
            'receivedAt' => $order->received_at?->valueOf(),
        ];
    }
}
