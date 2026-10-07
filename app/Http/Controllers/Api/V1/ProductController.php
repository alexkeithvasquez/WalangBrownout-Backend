<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()->where('is_active', true)
            ->when($request->filled('q'), fn ($query) => $query->where(function ($q) use ($request): void {
                $term = '%'.$request->string('q').'%';
                $q->where('name', 'like', $term)->orWhere('sku', 'like', $term);
            }))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->boolean('in_stock'), fn ($query) => $query->where('stock_quantity', '>', 0))
            ->orderBy('name')->get();

        return response()->json(['data' => $products->map(fn (Product $product) => $this->productData($product))]);
    }

    public function show(string $identifier): JsonResponse
    {
        $product = Product::where('sku', $identifier)->orWhere('slug', $identifier)->firstOrFail();

        return response()->json(['data' => $this->productData($product)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'slug' => ['nullable', 'string', 'max:120', 'unique:products,slug'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:120'],
            'price' => ['required_without:unit_price', 'numeric', 'min:0'],
            'unit_price' => ['required_without:price', 'numeric', 'min:0'],
            'reorder_point' => ['sometimes', 'integer', 'min:0'],
            'opening_qty' => ['sometimes', 'integer', 'min:0'],
            'stock_quantity' => ['prohibited'],
            'bin' => ['nullable', 'string', 'max:100'],
            'bin_location' => ['nullable', 'string', 'max:100'],
            'expiry' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $product = DB::transaction(function () use ($data, $request): Product {
            $opening = (int) ($data['opening_qty'] ?? 0);
            $product = Product::create([
                'sku' => trim($data['sku']),
                'slug' => $data['slug'] ?? null,
                'name' => trim($data['name']),
                'category' => $data['category'],
                'unit_price' => $data['unit_price'] ?? $data['price'],
                'stock_quantity' => $opening,
                'reorder_point' => (int) ($data['reorder_point'] ?? 0),
                'bin_location' => $data['bin_location'] ?? $data['bin'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? $data['expiry'] ?? null,
            ]);

            if ($opening > 0) {
                $batch = $product->batches()->create([
                    'batch_code' => 'OPEN-'.strtoupper(bin2hex(random_bytes(4))),
                    'quantity_received' => $opening,
                    'quantity_remaining' => $opening,
                    'received_at' => now(),
                    'expiry_date' => $product->expiry_date,
                    'bin_location' => $product->bin_location,
                ]);
                InventoryMovement::create([
                    'product_id' => $product->id,
                    'inventory_batch_id' => $batch->id,
                    'user_id' => $request->user()->id,
                    'type' => 'opening_stock',
                    'quantity' => $opening,
                    'reference' => 'PRODUCT-'.$product->sku,
                ]);
            }

            return $product;
        });

        return response()->json(['data' => $this->productData($product)], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:120'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'reorder_point' => ['sometimes', 'integer', 'min:0'],
            'bin' => ['sometimes', 'nullable', 'string', 'max:100'],
            'bin_location' => ['sometimes', 'nullable', 'string', 'max:100'],
            'expiry' => ['sometimes', 'nullable', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'stock_quantity' => ['prohibited'],
        ]);
        foreach (['price' => 'unit_price', 'bin' => 'bin_location', 'expiry' => 'expiry_date'] as $from => $to) {
            if (array_key_exists($from, $data)) {
                $data[$to] = $data[$from];
                unset($data[$from]);
            }
        }
        $product->update($data);

        return response()->json(['data' => $this->productData($product->fresh())]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeStaff($request);
        $product->update(['is_active' => false]);

        return response()->json(['message' => 'Product archived.']);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole('staff', 'manager', 'admin'), 403, 'Staff access required.');
    }

    private function productData(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'slug' => $product->slug,
            'name' => $product->name,
            'category' => $product->category,
            'price' => (float) $product->unit_price,
            'unit_price' => (float) $product->unit_price,
            'available' => $product->stock_quantity,
            'stock_quantity' => $product->stock_quantity,
            'reorder_point' => $product->reorder_point,
            'bin' => $product->bin_location,
            'bin_location' => $product->bin_location,
            'expiry' => $product->expiry_date?->toDateString(),
            'is_active' => $product->is_active,
        ];
    }
}
