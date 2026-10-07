<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('customer')->after('password')->index();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('name');
            $table->string('category')->default('Other')->index();
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('reorder_point')->default(0);
            $table->string('bin_location')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_code');
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_remaining');
            $table->timestamp('received_at');
            $table->date('expiry_date')->nullable();
            $table->string('bin_location')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'batch_code']);
            $table->index(['product_id', 'expiry_date', 'received_at']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->integer('quantity');
            $table->string('reference')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('estimated_cost', 12, 2);
            $table->string('status')->default('Pending')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('total', 12, 2);
            $table->string('payment_method');
            $table->string('status')->default('Processing')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku');
            $table->string('product_name');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });

        Schema::create('inventory_discrepancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('system_quantity');
            $table->unsignedInteger('counted_quantity');
            $table->integer('variance');
            $table->string('status')->default('Investigating')->index();
            $table->timestamp('flagged_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_discrepancies');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('products');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
