<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'code', 'product_id', 'quantity', 'estimated_cost', 'status', 'created_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'estimated_cost' => 'decimal:2',
            'received_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
