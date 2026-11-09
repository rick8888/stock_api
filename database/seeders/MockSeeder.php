<?php

namespace Database\Seeders;

use App\Models\AppNotification;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Company;
use App\Models\Consultation;
use App\Models\Delivery;
use App\Models\Driver;
use App\Models\Expense;
use App\Models\InventorySession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlanningEvent;
use App\Models\PlatformInvoice;
use App\Models\ProcurementRequest;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Route;
use App\Models\Shipment;
use App\Models\ShippingNote;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\TaskItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class MockSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/mock.json');
        if (! is_file($path)) {
            throw new \RuntimeException('Lancez npm run export-seed dans aria pour générer database/data/mock.json');
        }

        $mock = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($mock['companies'] ?? [] as $row) {
            Company::query()->updateOrCreate(
                ['id' => $row['id']],
                [
                    'name' => $row['name'],
                    'legal_name' => $row['legalName'],
                    'ice' => $row['ice'] ?? null,
                    'city' => $row['city'] ?? null,
                    'email' => $row['email'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'status' => $row['status'] ?? 'active',
                    'users_count' => $row['usersCount'] ?? 0,
                    'warehouses_count' => $row['warehousesCount'] ?? 0,
                    'created_at' => $row['createdAt'] ?? now(),
                ],
            );
        }

        foreach ($mock['roles'] ?? [] as $row) {
            Role::query()->updateOrCreate(
                ['id' => $row['id']],
                [
                    'key' => $row['key'],
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'permissions' => $row['permissions'] ?? [],
                ],
            );
        }

        foreach ($mock['users'] ?? [] as $row) {
            User::query()->updateOrCreate(
                ['id' => $row['id']],
                [
                    'first_name' => $row['firstName'],
                    'last_name' => $row['lastName'],
                    'email' => $row['email'],
                    'password' => $row['password'] ?? 'demo',
                    'role' => $row['role'],
                    'title' => $row['title'] ?? '',
                    'avatar_hue' => $row['avatarHue'] ?? 0,
                    'warehouse_ids' => $row['warehouseIds'] ?? [],
                    'product_ids' => $row['productIds'] ?? [],
                    'region_ids' => $row['regionIds'] ?? [],
                    'driver_id' => $row['driverId'] ?? null,
                    'company_id' => $row['companyId'] ?? null,
                ],
            );
        }

        $this->docs($mock['permissions'] ?? [], Permission::class);
        $this->docs($mock['categories'] ?? [], Category::class);
        $this->docs($mock['warehouses'] ?? [], Warehouse::class);
        $this->docs($mock['products'] ?? [], Product::class);
        $this->docs($mock['suppliers'] ?? [], Supplier::class);
        $this->docs($mock['stock'] ?? [], StockItem::class);
        $this->docs($mock['stockMovements'] ?? [], StockMovement::class);
        $this->docs($mock['inventories'] ?? [], InventorySession::class);
        $this->docs($mock['procurementRequests'] ?? [], ProcurementRequest::class);
        $this->docs($mock['consultations'] ?? [], Consultation::class);
        $this->docs($mock['purchaseOrders'] ?? [], PurchaseOrder::class);
        $this->docs($mock['receipts'] ?? [], Receipt::class);
        $this->docs($mock['drivers'] ?? [], Driver::class);
        $this->docs($mock['vehicles'] ?? [], Vehicle::class);
        $this->docs($mock['shippingNotes'] ?? [], ShippingNote::class);
        $this->docs($mock['routes'] ?? [], Route::class);
        $this->docs($mock['shipments'] ?? [], Shipment::class);
        $this->docs($mock['deliveries'] ?? [], Delivery::class);
        $this->docs($mock['tasks'] ?? [], TaskItem::class);
        $this->docs($mock['alerts'] ?? [], Alert::class);
        $this->docs($mock['planning'] ?? [], PlanningEvent::class);
        $this->docs($mock['invoices'] ?? [], Invoice::class);
        $this->docs($mock['payments'] ?? [], Payment::class);
        $this->docs($mock['budgets'] ?? [], Budget::class);
        $this->docs($mock['expenses'] ?? [], Expense::class);
        $this->docs($mock['plans'] ?? [], SubscriptionPlan::class);
        $this->docs($mock['subscriptions'] ?? [], Subscription::class);
        $this->docs($mock['platformInvoices'] ?? [], PlatformInvoice::class);
        $this->docs($mock['notifications'] ?? [], AppNotification::class);
        $this->docs($mock['auditLogs'] ?? [], AuditLog::class);
    }

    private function docs(array $rows, string $model): void
    {
        foreach ($rows as $row) {
            $model::query()->updateOrCreate(
                ['id' => $row['id']],
                ['payload' => $row],
            );
        }
    }
}
