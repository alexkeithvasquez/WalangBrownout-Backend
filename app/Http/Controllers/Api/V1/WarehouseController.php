<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryDiscrepancy;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function batches(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $batches = InventoryBatch::with('product')
            ->when($request->filled('sku'), fn ($query) => $query->whereHas('product', fn ($q) => $q->where('sku', $request->string('sku'))))
            ->orderByDesc('received_at')->limit(250)->get()
            ->map(fn (InventoryBatch $batch) => $this->batchData($batch));

        return response()->json(['data' => $batches]);
    }

    public function receive(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
            'batch_code' => ['nullable', 'string', 'max:100'],
            'expiry' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'bin' => ['nullable', 'string', 'max:100'],
            'bin_location' => ['nullable', 'string', 'max:100'],
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
        ]);

        $result = DB::transaction(function () use ($request, $data): InventoryBatch {
            $product = Product::where('sku', $data['sku'])->lockForUpdate()->first();
            if (! $product) {
                throw ValidationException::withMessages(['sku' => ['This SKU is not registered yet. Register it first.']]);
            }
            $batchCode = $data['batch_code'] ?? 'LOT-'.strtoupper(bin2hex(random_bytes(3)));
            if ($product->batches()->where('batch_code', $batchCode)->exists()) {
                throw ValidationException::withMessages(['batch_code' => ['That batch code already exists for this SKU.']]);
            }
            $qty = (int) $data['qty'];
            $batch = $product->batches()->create([
                'batch_code' => $batchCode,
                'quantity_received' => $qty,
                'quantity_remaining' => $qty,
                'received_at' => now(),
                'expiry_date' => $data['expiry_date'] ?? $data['expiry'] ?? null,
                'bin_location' => $data['bin_location'] ?? $data['bin'] ?? $product->bin_location,
            ]);
            $product->increment('stock_quantity', $qty);
            InventoryMovement::create([
                'product_id' => $product->id,
                'inventory_batch_id' => $batch->id,
                'user_id' => $request->user()->id,
                'type' => 'receipt',
                'quantity' => $qty,
                'reference' => $data['purchase_order_id'] ?? $batchCode,
            ]);

            if (! empty($data['purchase_order_id'])) {
                $po = \App\Models\PurchaseOrder::lockForUpdate()->findOrFail($data['purchase_order_id']);
                if ($po->product_id !== $product->id || $po->status !== 'Pending') {
                    throw ValidationException::withMessages(['purchase_order_id' => ['This purchase order is not open for this SKU.']]);
                }
                $po->update(['status' => 'Received', 'received_at' => now()]);
            }

            return $batch->load('product');
        });

        return response()->json(['data' => $this->batchData($result)], 201);
    }

    public function pick(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
            'batch_code' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $picked = DB::transaction(function () use ($request, $data): array {
            $product = Product::where('sku', $data['sku'])->lockForUpdate()->firstOrFail();
            $qty = (int) $data['qty'];
            if ($product->stock_quantity < $qty) {
                throw ValidationException::withMessages(['qty' => ['Not enough stock for this pick.']]);
            }
            $batches = $product->batches()->where('quantity_remaining', '>', 0)
                ->where(fn ($query) => $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', today()))
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expiry_date')->orderBy('received_at')->lockForUpdate()->get();
            $oldest = $batches->first();
            if (! $oldest) {
                throw ValidationException::withMessages(['sku' => ['No eligible inventory batch is available.']]);
            }
            if (! empty($data['batch_code']) && $oldest->batch_code !== $data['batch_code']) {
                throw ValidationException::withMessages(['batch_code' => ['FIFO enforced: pick batch '.$oldest->batch_code.' first.']]);
            }

            $remaining = $qty;
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
                    'type' => 'pick',
                    'quantity' => -$taken,
                    'reference' => $data['reference'] ?? null,
                ]);
                $remaining -= $taken;
            }
            if ($remaining > 0) {
                throw ValidationException::withMessages(['qty' => ['Inventory batch quantities do not match the stock balance.']]);
            }
            $product->decrement('stock_quantity', $qty);

            return ['sku' => $product->sku, 'picked' => $qty, 'remaining' => $product->fresh()->stock_quantity, 'fifo_batch' => $oldest->batch_code];
        });

        return response()->json(['data' => $picked]);
    }

    public function count(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'sku' => ['required', 'string'],
            'counted_qty' => ['required', 'integer', 'min:0'],
            'bin' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($request, $data): array {
            $product = Product::where('sku', $data['sku'])->lockForUpdate()->firstOrFail();
            $system = (int) $product->stock_quantity;
            $counted = (int) $data['counted_qty'];
            $variance = $counted - $system;
            if (abs($variance) > 2) {
                $discrepancy = InventoryDiscrepancy::create([
                    'product_id' => $product->id,
                    'system_quantity' => $system,
                    'counted_quantity' => $counted,
                    'variance' => $variance,
                    'status' => 'Investigating',
                    'flagged_at' => now(),
                    'created_by' => $request->user()->id,
                    'notes' => $data['notes'] ?? null,
                ]);

                return ['flagged' => true, 'discrepancy' => $discrepancy->load('product'), 'message' => 'Count variance exceeds the ±2 tolerance; stock remains unchanged pending review.'];
            }

            if ($variance !== 0) {
                if ($variance > 0) {
                    $batch = $product->batches()->create([
                        'batch_code' => 'COUNT-'.strtoupper(bin2hex(random_bytes(3))),
                        'quantity_received' => $variance,
                        'quantity_remaining' => $variance,
                        'received_at' => now(),
                        'bin_location' => $data['bin'] ?? $product->bin_location,
                    ]);
                    InventoryMovement::create([
                        'product_id' => $product->id, 'inventory_batch_id' => $batch->id,
                        'user_id' => $request->user()->id, 'type' => 'cycle_count_adjustment',
                        'quantity' => $variance, 'notes' => $data['notes'] ?? null,
                    ]);
                } else {
                    $toRemove = abs($variance);
                    $batches = $product->batches()->where('quantity_remaining', '>', 0)->orderBy('received_at')->lockForUpdate()->get();
                    foreach ($batches as $batch) {
                        if ($toRemove === 0) break;
                        $removed = min($toRemove, $batch->quantity_remaining);
                        $batch->decrement('quantity_remaining', $removed);
                        InventoryMovement::create([
                            'product_id' => $product->id, 'inventory_batch_id' => $batch->id,
                            'user_id' => $request->user()->id, 'type' => 'cycle_count_adjustment',
                            'quantity' => -$removed, 'notes' => $data['notes'] ?? null,
                        ]);
                        $toRemove -= $removed;
                    }
                }
                $product->update(['stock_quantity' => $counted]);
            }

            return ['flagged' => false, 'sku' => $product->sku, 'system_quantity' => $system, 'counted_quantity' => $counted, 'variance' => $variance, 'status' => 'Resolved'];
        });

        return response()->json(['data' => $result], 201);
    }

    public function discrepancies(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = InventoryDiscrepancy::with('product')->latest()->get()->map(fn (InventoryDiscrepancy $d) => [
            'id' => $d->id, 'sku' => $d->product->sku, 'name' => $d->product->name,
            'system' => $d->system_quantity, 'counted' => $d->counted_quantity,
            'variance' => $d->variance, 'status' => $d->status, 'flagged_at' => $d->flagged_at,
        ]);

        return response()->json(['data' => $data]);
    }

    public function resolveDiscrepancy(Request $request, InventoryDiscrepancy $discrepancy): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole('manager', 'admin'), 403, 'Manager access required to resolve discrepancies.');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000'], 'apply_counted_stock' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($request, $discrepancy, $data): void {
            $discrepancy = InventoryDiscrepancy::lockForUpdate()->findOrFail($discrepancy->id);
            if ($discrepancy->status === 'Resolved') {
                throw ValidationException::withMessages(['status' => ['This discrepancy is already resolved.']]);
            }
            $product = Product::lockForUpdate()->findOrFail($discrepancy->product_id);
            if (($data['apply_counted_stock'] ?? false) === true) {
                $delta = $discrepancy->counted_quantity - $product->stock_quantity;
                if ($delta > 0) {
                    $batch = $product->batches()->create([
                        'batch_code' => 'RESOLVE-'.strtoupper(bin2hex(random_bytes(3))),
                        'quantity_received' => $delta, 'quantity_remaining' => $delta,
                        'received_at' => now(), 'bin_location' => $product->bin_location,
                    ]);
                    InventoryMovement::create([
                        'product_id' => $product->id, 'inventory_batch_id' => $batch->id,
                        'user_id' => $request->user()->id, 'type' => 'discrepancy_resolution', 'quantity' => $delta,
                    ]);
                } elseif ($delta < 0) {
                    $remaining = abs($delta);
                    foreach ($product->batches()->where('quantity_remaining', '>', 0)->orderBy('received_at')->lockForUpdate()->get() as $batch) {
                        if ($remaining === 0) break;
                        $removed = min($remaining, $batch->quantity_remaining);
                        $batch->decrement('quantity_remaining', $removed);
                        InventoryMovement::create([
                            'product_id' => $product->id, 'inventory_batch_id' => $batch->id,
                            'user_id' => $request->user()->id, 'type' => 'discrepancy_resolution', 'quantity' => -$removed,
                        ]);
                        $remaining -= $removed;
                    }
                }
                $product->update(['stock_quantity' => $discrepancy->counted_quantity]);
            }
            $discrepancy->update(['status' => 'Resolved', 'resolved_at' => now(), 'notes' => $data['notes'] ?? $discrepancy->notes]);
        });

        return response()->json(['data' => $discrepancy->fresh()->load('product')]);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole('staff', 'manager', 'admin'), 403, 'Staff access required.');
    }

    private function batchData(InventoryBatch $batch): array
    {
        return [
            'id' => $batch->id, 'sku' => $batch->product?->sku, 'name' => $batch->product?->name,
            'batch' => $batch->batch_code, 'qty' => $batch->quantity_remaining,
            'quantity_received' => $batch->quantity_received,
            'receiptDate' => $batch->received_at?->toISOString(),
            'expiry' => $batch->expiry_date?->toDateString(), 'bin' => $batch->bin_location,
        ];
    }
}
