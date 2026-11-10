<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\DriverRepository;
use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Repositories\Eloquent\VehicleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalFleetPurchasingSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-fp',
            'first_name' => 'Fleet',
            'last_name' => 'Buy',
            'email' => 'fp@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 2,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-fp',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_fleet_and_purchasing_relational_persist(): void
    {
        $vehicle = app(VehicleRepository::class)->put([
            'id' => 'veh-1',
            'name' => 'Fourgon 1',
            'plate' => '12345-A-6',
            'status' => 'available',
            'warehouseId' => 'wh-1',
            'companyId' => 'co-fp',
        ]);
        $this->assertSame('12345-A-6', $vehicle['plate']);
        $this->assertDatabaseHas('vehicles', [
            'id' => 'veh-1',
            'plate' => '12345-A-6',
            'warehouse_id' => 'wh-1',
        ]);

        $driver = app(DriverRepository::class)->put([
            'id' => 'drv-1',
            'userId' => 'usr-fp',
            'vehicleId' => 'veh-1',
            'phone' => '0600000000',
            'status' => 'available',
            'warehouseId' => 'wh-1',
            'companyId' => 'co-fp',
        ]);
        $this->assertSame('usr-fp', $driver['userId']);
        $this->assertDatabaseHas('drivers', [
            'id' => 'drv-1',
            'user_id' => 'usr-fp',
            'warehouse_id' => 'wh-1',
        ]);

        $request = app(ProcurementRequestRepository::class)->put([
            'id' => 'pr-1',
            'code' => 'DA-100',
            'requesterId' => 'usr-fp',
            'warehouseId' => 'wh-1',
            'status' => 'approved',
            'priority' => 'high',
            'note' => 'Urgence',
            'createdAt' => '2026-11-10T10:00:00+01:00',
            'companyId' => 'co-fp',
            'items' => [
                ['productId' => 'prd-1', 'quantity' => 8],
                ['productId' => 'prd-2', 'quantity' => 3],
            ],
        ]);
        $this->assertCount(2, $request['items']);
        $this->assertDatabaseCount('procurement_request_items', 2);

        $order = app(PurchaseOrderRepository::class)->put([
            'id' => 'po-1',
            'code' => 'OA-1031',
            'supplierId' => 'sup-1',
            'warehouseId' => 'wh-1',
            'status' => 'ordered',
            'expectedDate' => '2026-11-15',
            'createdAt' => '2026-11-10T11:00:00+01:00',
            'amount' => 1250.5,
            'procurementRequestId' => 'pr-1',
            'procurementRequestIds' => ['pr-1'],
            'companyId' => 'co-fp',
            'items' => [
                ['productId' => 'prd-1', 'quantity' => 8],
            ],
        ]);
        $this->assertSame(1250.5, (float) $order['amount']);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => 'po-1',
            'supplier_id' => 'sup-1',
            'warehouse_id' => 'wh-1',
        ]);
        $this->assertDatabaseCount('purchase_order_items', 1);

        $receipt = app(ReceiptRepository::class)->put([
            'id' => 'rcp-1',
            'code' => 'BR-11',
            'purchaseOrderId' => 'po-1',
            'warehouseId' => 'wh-1',
            'expected' => 8,
            'received' => 5,
            'date' => '2026-11-10T15:00:00+01:00',
            'status' => 'partial',
            'userId' => 'usr-fp',
            'companyId' => 'co-fp',
            'items' => [
                [
                    'productId' => 'prd-1',
                    'expected' => 8,
                    'received' => 5,
                    'lotCode' => 'LOT-1',
                    'expiryDate' => '2027-01-01',
                    'locationId' => 'loc-1',
                ],
            ],
        ]);
        $this->assertSame(5, (int) $receipt['items'][0]['received']);
        $this->assertSame('LOT-1', $receipt['items'][0]['lotCode']);
        $this->assertDatabaseHas('receipts', [
            'id' => 'rcp-1',
            'purchase_order_id' => 'po-1',
            'status' => 'partial',
        ]);
        $this->assertDatabaseCount('receipt_items', 1);

        $this->assertCount(1, app(VehicleRepository::class)->forWarehouses(['wh-1']));
        $this->assertCount(1, app(ProcurementRequestRepository::class)->forWarehouses(['wh-1']));
        $this->assertCount(1, app(PurchaseOrderRepository::class)->forWarehouses(['wh-1']));
        $this->assertCount(1, app(ReceiptRepository::class)->forWarehouses(['wh-1']));
    }
}
