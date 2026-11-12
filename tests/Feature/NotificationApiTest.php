<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\OpsNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        Company::query()->create([
            'id' => 'co-ntf',
            'name' => 'Ntf Co',
            'legal_name' => 'Ntf Co',
            'ice' => '',
            'city' => 'Casa',
            'email' => 'ntf@test.local',
            'phone' => '',
            'status' => 'active',
            'users_count' => 1,
            'warehouses_count' => 1,
        ]);

        $this->driver = User::query()->create([
            'id' => 'usr-ntf-drv',
            'first_name' => 'Karim',
            'last_name' => 'Route',
            'email' => 'ntf-driver@test.local',
            'password' => 'demo',
            'role' => 'DRIVER',
            'title' => 'Chauffeur',
            'avatar_hue' => 20,
            'warehouse_ids' => ['wh-a'],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-ntf',
        ]);
    }

    public function test_list_and_mark_read(): void
    {
        $this->actingAs($this->driver, 'api');
        app(OpsNotifier::class)->notify('Livraison assignée', 'Stop 1 prêt', 'info', 'usr-ntf-drv');
        app(OpsNotifier::class)->notify('Broadcast company', 'Message général', 'info', null);

        $response = $this->getJson('/api/notifications');
        $response->assertOk();
        $payload = $response->json('data') ?? $response->json();
        $this->assertGreaterThanOrEqual(2, count($payload));

        $id = $payload[0]['id'] ?? null;
        $this->assertNotEmpty($id);
        $this->postJson('/api/notifications/'.$id.'/read')->assertNoContent();
        $this->postJson('/api/notifications/read-all')->assertOk();
    }
}
