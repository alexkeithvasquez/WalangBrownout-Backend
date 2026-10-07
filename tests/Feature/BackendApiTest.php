<?php

namespace Tests\Feature;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_creates_a_customer_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Casey Customer',
            'email' => 'casey@example.com',
            'password' => 'a-long-enough-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'role'], 'token']]);
        $this->assertDatabaseHas('users', ['email' => 'casey@example.com', 'role' => 'customer']);
    }

    public function test_public_registration_cannot_assign_a_privileged_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Untrusted User',
            'email' => 'untrusted@example.com',
            'password' => 'a-long-enough-password',
            'role' => 'manager',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'untrusted@example.com']);
    }

    public function test_login_returns_a_sanctum_token_and_me_requires_authentication(): void
    {
        $user = User::create(['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'password123', 'role' => 'customer']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123']);
        $response->assertOk()->assertJsonPath('data.user.email', $user->email);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_order_uses_server_price_and_reduces_stock(): void
    {
        $customer = User::create(['name' => 'Customer', 'email' => 'customer@example.com', 'password' => 'password123', 'role' => 'customer']);
        $product = Product::create([
            'sku' => 'FAN-01', 'slug' => 'fan-01', 'name' => 'Test Fan', 'category' => 'Other',
            'unit_price' => 125.50, 'stock_quantity' => 5, 'reorder_point' => 1,
        ]);
        InventoryBatch::create([
            'product_id' => $product->id, 'batch_code' => 'LOT-1', 'quantity_received' => 5,
            'quantity_remaining' => 5, 'received_at' => now(),
        ]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['productId' => 'fan-01', 'qty' => 2, 'price' => 0.01]],
            'payment' => 'Cash on Delivery',
        ]);

        $response->assertCreated()->assertJsonPath('data.total', 251);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 3]);
        $this->assertDatabaseHas('inventory_batches', ['product_id' => $product->id, 'quantity_remaining' => 3]);
    }

    public function test_customer_cannot_read_another_customers_orders(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password123', 'role' => 'customer']);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password123', 'role' => 'customer']);
        $order = \App\Models\Order::create([
            'code' => 'WB-TEST-1', 'user_id' => $owner->id, 'total' => 0,
            'payment_method' => 'Cash on Delivery', 'status' => 'Processing',
        ]);

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/orders/'.$order->id)->assertForbidden();
    }

    public function test_cancelling_an_order_restores_product_and_batch_stock(): void
    {
        $customer = User::create(['name' => 'Buyer', 'email' => 'buyer@example.com', 'password' => 'password123', 'role' => 'customer']);
        $product = Product::create([
            'sku' => 'LAMP-01', 'slug' => 'lamp-01', 'name' => 'Test Lamp', 'category' => 'Other',
            'unit_price' => 100, 'stock_quantity' => 4, 'reorder_point' => 1,
        ]);
        InventoryBatch::create([
            'product_id' => $product->id, 'batch_code' => 'LAMP-LOT', 'quantity_received' => 4,
            'quantity_remaining' => 4, 'received_at' => now(),
        ]);

        $order = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['sku' => 'LAMP-01', 'qty' => 3]], 'payment' => 'GCash',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/orders/'.$order['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'Cancelled');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 4]);
        $this->assertDatabaseHas('inventory_batches', ['product_id' => $product->id, 'quantity_remaining' => 4]);
    }

    public function test_warehouse_pick_enforces_fifo_batch_order(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'password123', 'role' => 'staff']);
        $product = Product::create([
            'sku' => 'FILTER-01', 'name' => 'Filter', 'category' => 'Filters',
            'unit_price' => 10, 'stock_quantity' => 5, 'reorder_point' => 1,
        ]);
        InventoryBatch::create([
            'product_id' => $product->id, 'batch_code' => 'OLDER', 'quantity_received' => 2,
            'quantity_remaining' => 2, 'received_at' => now()->subDays(5),
        ]);
        InventoryBatch::create([
            'product_id' => $product->id, 'batch_code' => 'NEWER', 'quantity_received' => 3,
            'quantity_remaining' => 3, 'received_at' => now(),
        ]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/warehouse/picks', [
            'sku' => 'FILTER-01', 'qty' => 1, 'batch_code' => 'NEWER',
        ])->assertUnprocessable()->assertJsonValidationErrors('batch_code');

        $this->assertDatabaseHas('inventory_batches', ['batch_code' => 'OLDER', 'quantity_remaining' => 2]);
    }

    public function test_receiving_a_purchase_order_increases_stock_and_closes_order(): void
    {
        $staff = User::create(['name' => 'Buyer', 'email' => 'purchasing@example.com', 'password' => 'password123', 'role' => 'staff']);
        $product = Product::create([
            'sku' => 'AC-TEST', 'name' => 'Test AC', 'category' => 'Air Conditioners',
            'unit_price' => 200, 'stock_quantity' => 0, 'reorder_point' => 5,
        ]);

        $purchaseOrder = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/purchasing/orders', [
            'sku' => 'AC-TEST', 'qty' => 6,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/purchasing/orders/'.$purchaseOrder['id'].'/receive', [
            'qty' => 6, 'batch_code' => 'AC-RECEIPT-1',
        ])->assertOk()->assertJsonPath('data.status', 'Received');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 6]);
        $this->assertDatabaseHas('inventory_batches', ['batch_code' => 'AC-RECEIPT-1', 'quantity_remaining' => 6]);
    }
}
