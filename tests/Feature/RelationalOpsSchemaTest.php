<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\AlertRepository;
use App\Repositories\Eloquent\BudgetRepository;
use App\Repositories\Eloquent\ConsultationRepository;
use App\Repositories\Eloquent\ExpenseRepository;
use App\Repositories\Eloquent\InvoiceRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalOpsSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-ops',
            'first_name' => 'Ops',
            'last_name' => 'Rel',
            'email' => 'opsrel@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-ops',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_consultations_finance_tasks_alerts_relational_persist(): void
    {
        $consultation = app(ConsultationRepository::class)->put([
            'id' => 'cs-1',
            'code' => 'CS-2026-00012',
            'status' => 'sent',
            'warehouseId' => 'wh-1',
            'procurementRequestIds' => ['pr-1'],
            'supplierIds' => ['sup-1', 'sup-2'],
            'createdAt' => '2026-11-10T09:00:00+01:00',
            'quotes' => [
                ['id' => 'q-1', 'supplierId' => 'sup-1', 'status' => 'pending'],
            ],
            'companyId' => 'co-ops',
            'items' => [
                ['productId' => 'prd-1', 'quantity' => 10],
            ],
        ]);
        $this->assertSame(['sup-1', 'sup-2'], $consultation['supplierIds']);
        $this->assertCount(1, $consultation['items']);
        $this->assertDatabaseHas('consultations', [
            'id' => 'cs-1',
            'warehouse_id' => 'wh-1',
            'status' => 'sent',
        ]);
        $this->assertDatabaseCount('consultation_items', 1);

        app(InvoiceRepository::class)->put([
            'id' => 'invc-1',
            'supplierId' => 'sup-1',
            'purchaseOrderId' => 'po-1',
            'amount' => 990,
            'status' => 'pending',
            'dueDate' => '2026-12-01',
            'companyId' => 'co-ops',
        ]);
        app(PaymentRepository::class)->put([
            'id' => 'pay-1',
            'invoiceId' => 'invc-1',
            'amount' => 990,
            'date' => '2026-11-10T12:00:00+01:00',
            'companyId' => 'co-ops',
        ]);
        app(BudgetRepository::class)->put([
            'id' => 'bdg-1',
            'name' => 'Ops Q4',
            'period' => '2026-Q4',
            'allocated' => 10000,
            'spent' => 1200,
            'companyId' => 'co-ops',
        ]);
        app(ExpenseRepository::class)->put([
            'id' => 'exp-1',
            'label' => 'Carburant',
            'amount' => 350,
            'date' => '2026-11-09T08:00:00+01:00',
            'category' => 'logistics',
            'companyId' => 'co-ops',
        ]);

        $this->assertDatabaseHas('invoices', ['id' => 'invc-1', 'amount' => 990, 'status' => 'pending']);
        $this->assertDatabaseHas('payments', ['id' => 'pay-1', 'invoice_id' => 'invc-1']);
        $this->assertDatabaseHas('budgets', ['id' => 'bdg-1', 'period' => '2026-Q4']);
        $this->assertDatabaseHas('expenses', ['id' => 'exp-1', 'category' => 'logistics']);

        app(TaskItemRepository::class)->put([
            'id' => 'tsk-1',
            'title' => 'Relancer fournisseur',
            'description' => 'Appeler',
            'assigneeId' => 'usr-ops',
            'dueAt' => '2026-11-11T18:00:00+01:00',
            'status' => 'todo',
            'priority' => 'high',
            'relatedType' => 'purchase_order',
            'relatedId' => 'po-1',
            'warehouseId' => 'wh-1',
            'companyId' => 'co-ops',
        ]);
        app(AlertRepository::class)->put([
            'id' => 'al-1',
            'title' => 'Rupture',
            'message' => 'Stock bas',
            'severity' => 'warning',
            'createdAt' => '2026-11-10T10:00:00+01:00',
            'read' => false,
            'domain' => 'stock',
            'warehouseId' => 'wh-1',
            'roles' => ['WAREHOUSE_MANAGER'],
            'companyId' => 'co-ops',
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => 'tsk-1',
            'assignee_id' => 'usr-ops',
            'priority' => 'high',
        ]);
        $this->assertDatabaseHas('alerts', [
            'id' => 'al-1',
            'domain' => 'stock',
            'severity' => 'warning',
        ]);

        $this->assertCount(1, app(InvoiceRepository::class)->forCompany('co-ops'));
        $this->assertCount(1, app(AlertRepository::class)->forWarehouses(['wh-1']));
        $this->assertCount(1, app(ConsultationRepository::class)->forWarehouses(['wh-1']));
    }
}
