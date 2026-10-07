<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku', 'slug', 'name', 'category', 'unit_price', 'stock_quantity',
        'reorder_point', 'bin_location', 'expiry_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'reorder_point' => 'integer',
            'expiry_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
