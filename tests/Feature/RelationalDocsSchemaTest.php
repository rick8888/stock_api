<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\Eloquent\AppNotificationRepository;
use App\Repositories\Eloquent\AuditLogRepository;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\PlanningEventRepository;
use App\Repositories\Eloquent\SupplierRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationalDocsSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
        $actor = User::query()->create([
            'id' => 'usr-docs',
            'first_name' => 'Docs',
            'last_name' => 'Rel',
            'email' => 'docsrel@test.local',
            'password' => 'demo',
            'role' => 'SUPER_ADMIN',
            'title' => 'Admin',
            'avatar_hue' => 1,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-docs',
        ]);
        $this->actingAs($actor, 'api');
    }

    public function test_suppliers_customers_planning_notifications_audits_relational_persist(): void
    {
        $supplier = app(SupplierRepository::class)->put([
            'id' => 'sup-1',
            'name' => 'Agro Sud',
            'contact' => 'Sara',
            'email' => 'sara@agro.test',
            'phone' => '0600000000',
            'city' => 'Agadir',
            'address' => 'Zone 1',
            'ice' => 'ICE1',
            'category' => 'food',
            'paymentTerms' => '30j',
            'note' => 'Prioritaire',
            'leadTimeDays' => 5,
            'reliability' => 90,
            'status' => 'active',
            'companyId' => 'co-docs',
        ]);
        $this->assertSame('Agro Sud', $supplier['name']);
        $this->assertDatabaseHas('suppliers', [
            'id' => 'sup-1',
            'city' => 'Agadir',
            'payment_terms' => '30j',
            'company_id' => 'co-docs',
        ]);

        $customer = app(CustomerRepository::class)->put([
            'id' => 'cus-1',
            'name' => 'Café Atlas',
            'phone' => '0611111111',
            'email' => 'cafe@atlas.test',
            'ice' => '',
            'notes' => '',
            'active' => true,
            'addresses' => [
                ['id' => 'adr-1', 'label' => 'Siège', 'address' => 'Bd Zerktouni', 'city' => 'Casablanca', 'isDefault' => true],
            ],
            'companyId' => 'co-docs',
        ]);
        $this->assertCount(1, $customer['addresses']);
        $this->assertDatabaseHas('customers', [
            'id' => 'cus-1',
            'name' => 'Café Atlas',
            'active' => 1,
            'company_id' => 'co-docs',
        ]);

        app(PlanningEventRepository::class)->put([
            'id' => 'pl-1',
            'title' => 'Livraison matin',
            'date' => '2026-11-11T08:00:00+01:00',
            'type' => 'delivery',
            'relatedId' => 'dlv-1',
            'warehouseId' => 'wh-1',
            'companyId' => 'co-docs',
        ]);
        $this->assertDatabaseHas('planning_events', [
            'id' => 'pl-1',
            'type' => 'delivery',
            'related_id' => 'dlv-1',
            'warehouse_id' => 'wh-1',
        ]);

        app(AppNotificationRepository::class)->put([
            'id' => 'ntf-1',
            'title' => 'Stock bas',
            'message' => 'Produit X',
            'createdAt' => '2026-11-10T10:00:00+01:00',
            'read' => false,
            'type' => 'warning',
            'userId' => 'usr-docs',
            'companyId' => 'co-docs',
        ]);
        app(AuditLogRepository::class)->put([
            'id' => 'aud-1',
            'userId' => 'usr-docs',
            'action' => 'Clôture de journée',
            'entity' => 'day',
            'entityId' => '2026-11-10',
            'createdAt' => '2026-11-10T18:00:00+01:00',
            'companyId' => 'co-docs',
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => 'ntf-1',
            'type' => 'warning',
            'user_id' => 'usr-docs',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'id' => 'aud-1',
            'action' => 'Clôture de journée',
            'entity_id' => '2026-11-10',
        ]);
        $this->assertTrue(app(AuditLogRepository::class)->has('Clôture de journée', '2026-11-10'));
        $this->assertCount(1, app(SupplierRepository::class)->forCompany('co-docs'));
        $this->assertCount(1, app(CustomerRepository::class)->forCompany('co-docs'));
        $this->assertCount(1, app(PlanningEventRepository::class)->forCompany('co-docs'));
    }
}
