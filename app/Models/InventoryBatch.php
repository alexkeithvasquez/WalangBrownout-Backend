<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    protected $fillable = [
        'product_id', 'batch_code', 'quantity_received', 'quantity_remaining',
        'received_at', 'expiry_date', 'bin_location',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'quantity_remaining' => 'integer',
            'received_at' => 'datetime',
            'expiry_date' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
