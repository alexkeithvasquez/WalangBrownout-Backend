<?php

namespace Database\Seeders;

use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['sku' => 'AC-Unit-12k', 'slug' => 'ac-12k', 'name' => 'Portable AC 12k BTU', 'category' => 'Air Conditioners', 'unit_price' => 18500, 'reorder_point' => 40, 'stock_quantity' => 18, 'bin_location' => 'C-01-1'],
            ['sku' => 'Smart-Therm-X', 'slug' => 'therm-x', 'name' => 'Smart Thermostat X', 'category' => 'Thermostats', 'unit_price' => 4200, 'reorder_point' => 15, 'stock_quantity' => 9, 'bin_location' => 'B-02-1'],
            ['sku' => 'Filter-Carbon-9m', 'slug' => 'filter-carbon', 'name' => 'Carbon Air Filter', 'category' => 'Filters', 'unit_price' => 650, 'reorder_point' => 25, 'stock_quantity' => 30, 'bin_location' => 'C-14-2'],
            ['sku' => 'Air-Purifier-Mini', 'slug' => 'purifier-mini', 'name' => 'Air Purifier Mini', 'category' => 'Air Purifiers', 'unit_price' => 6900, 'reorder_point' => 20, 'stock_quantity' => 27, 'bin_location' => 'D-05-3'],
        ];

        foreach ($items as $item) {
            $product = Product::updateOrCreate(['sku' => $item['sku']], $item);
            if ($product->batches()->exists()) {
                continue;
            }

            $batch = InventoryBatch::create([
                'product_id' => $product->id,
                'batch_code' => 'OPEN-'.strtoupper(substr(md5($product->sku), 0, 8)),
                'quantity_received' => $product->stock_quantity,
                'quantity_remaining' => $product->stock_quantity,
                'received_at' => now()->subMonths(2),
                'bin_location' => $product->bin_location,
            ]);
            InventoryMovement::create([
                'product_id' => $product->id,
                'inventory_batch_id' => $batch->id,
                'type' => 'opening_stock',
                'quantity' => $product->stock_quantity,
                'reference' => 'SEED-'.$product->sku,
                'notes' => 'Development catalogue opening balance.',
            ]);
        }
    }
}
