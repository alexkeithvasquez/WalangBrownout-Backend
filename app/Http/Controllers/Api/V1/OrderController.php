<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Order::with('items')->latest();
        if (! $request->user()->hasAnyRole('staff', 'manager', 'admin')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->get()->map(fn (Order $order) => $this->orderData($order))]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return response()->json(['data' => $this->orderData($order->load('items'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku' => ['nullable', 'string'],
            'items.*.product_id' => ['nullable'],
            'items.*.productId' => ['nullable', 'string'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000'],
            'payment' => ['required_without:payment_method', 'in:Cash on Delivery,GCash,Card'],
            'payment_method' => ['required_without:payment', 'in:Cash on Delivery,GCash,Card'],
        ]);

        $order = DB::transaction(function () use ($request, $data): Order {
            $code = 'WB-'.now()->format('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $order = Order::create([
                'code' => $code,
                'user_id' => $request->user()->id,
                'total' => 0,
                'payment_method' => $data['payment_method'] ?? $data['payment'],
                'status' => 'Processing',
            ]);
            $total = 0.0;

            foreach ($data['items'] as $item) {
                $identifier = $item['sku'] ?? $item['productId'] ?? $item['product_id'] ?? null;
                if (! is_string($identifier) && ! is_int($identifier)) {
                    throw ValidationException::withMessages(['items' => ['Each item needs a product SKU or slug.']]);
                }
                $product = Product::query()->where('is_active', true)
                    ->where(fn ($query) => $query->where('sku', (string) $identifier)->orWhere('slug', (string) $identifier))
                    ->lockForUpdate()->first();

                if (! $product) {
                    throw ValidationException::withMessages(['items' => ["Unknown product: {$identifier}."]]);
                }
                if ($product->stock_quantity < $item['qty']) {
                    throw ValidationException::withMessages(['items' => ["Insufficient stock for {$product->name}."]]);
                }

                $remaining = (int) $item['qty'];
                $batches = $product->batches()->where('quantity_remaining', '>', 0)
                    ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('expiry_date')->orderBy('received_at')->lockForUpdate()->get();
                foreach ($batches as $batch) {
                    if ($remaining === 0) {
                        break;
                    }
                    $taken = min($remaining, $batch->quantity_remaining);
                    $batch->decrement('quantity_remaining', $taken);
                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'inventory_batch_id' => $batch->id,
                        'user_id' => $request->user()->id,
                        'type' => 'sale',
                        'quantity' => -$taken,
                        'reference' => $code,
                    ]);
                    $remaining -= $taken;
                }
                if ($remaining > 0) {
                    throw ValidationException::withMessages(['items' => ["Inventory batches do not cover the available stock for {$product->name}."]]);
                }

                $product->decrement('stock_quantity', $item['qty']);
                $lineTotal = (float) $product->unit_price * (int) $item['qty'];
                $total += $lineTotal;
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'unit_price' => $product->unit_price,
                    'quantity' => $item['qty'],
                ]);
            }

            $order->update(['total' => $total]);

            return $order->load('items');
        });

        return response()->json(['data' => $this->orderData($order)], 201);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);
        $order = DB::transaction(function () use ($request, $order): Order {
            $order = Order::with('items')->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'Delivered') {
                throw ValidationException::withMessages(['status' => ['Delivered orders cannot be cancelled.']]);
            }
            if ($order->status === 'Cancelled') {
                throw ValidationException::withMessages(['status' => ['This order is already cancelled.']]);
            }

            $sales = InventoryMovement::where('reference', $order->code)->where('type', 'sale')->get();
            foreach ($sales as $sale) {
                $batch = $sale->batch;
                if ($batch) {
                    $batch->increment('quantity_remaining', abs($sale->quantity));
                }
                Product::whereKey($sale->product_id)->increment('stock_quantity', abs($sale->quantity));
                InventoryMovement::create([
                    'product_id' => $sale->product_id,
                    'inventory_batch_id' => $sale->inventory_batch_id,
                    'user_id' => $request->user()->id,
                    'type' => 'order_cancelled',
                    'quantity' => abs($sale->quantity),
                    'reference' => $order->code,
                ]);
            }
            $order->update(['status' => 'Cancelled', 'cancelled_at' => now()]);

            return $order->fresh('items');
        });

        return response()->json(['data' => $this->orderData($order)]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole('staff', 'manager', 'admin'), 403, 'Staff access required.');
        $data = $request->validate(['status' => ['required', 'in:Processing,In Transit,Delivered']]);
        if ($order->status === 'Cancelled') {
            throw ValidationException::withMessages(['status' => ['Cancelled orders cannot be updated.']]);
        }
        $order->update(['status' => $data['status']]);

        return response()->json(['data' => $this->orderData($order->fresh('items'))]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(
            $order->user_id === $request->user()->id || $request->user()->hasAnyRole('staff', 'manager', 'admin'),
            403,
            'You cannot access this order.',
        );
    }

    private function orderData(Order $order): array
    {
        return [
            'id' => (string) $order->getKey(),
            'code' => $order->code,
            'customer_id' => $order->user_id,
            'customer_name' => $order->customer?->name,
            'customer_email' => $order->customer?->email,
            'total' => (float) $order->total,
            'payment' => $order->payment_method,
            'payment_method' => $order->payment_method,
            'status' => $order->status,
            'createdAt' => $order->created_at?->valueOf(),
            'cancelledAt' => $order->cancelled_at?->valueOf(),
            'items' => $order->items->map(fn (OrderItem $item) => [
                'productId' => $item->product?->slug ?? $item->sku,
                'sku' => $item->sku,
                'name' => $item->product_name,
                'price' => (float) $item->unit_price,
                'qty' => $item->quantity,
            ])->all(),
        ];
    }
}
