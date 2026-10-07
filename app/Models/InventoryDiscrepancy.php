<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryDiscrepancy extends Model
{
    protected $fillable = [
        'product_id', 'system_quantity', 'counted_quantity', 'variance', 'status',
        'flagged_at', 'resolved_at', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'variance' => 'integer',
            'flagged_at' => 'datetime',
            'resolved_at' => 'datetime',
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
