<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanningBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $dispatcher;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->dispatcher = User::query()->create([
            'id' => 'usr-disp',
            'first_name' => 'Karim',
            'last_name' => 'Dispatch',
            'email' => 'dispatch-plan@test.local',
            'password' => 'demo',
            'role' => 'DISPATCHER',
            'title' => 'Dispatch',
            'avatar_hue' => 40,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);

        $this->driver = User::query()->create([
            'id' => 'usr-drv',
            'first_name' => 'Ali',
            'last_name' => 'Driver',
            'email' => 'driver-plan@test.local',
            'password' => 'demo',
            'role' => 'DRIVER',
            'title' => 'Chauffeur',
            'avatar_hue' => 80,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);

        DB::table('planning_events')->insert([
            [
                'id' => 'pl-meet',
                'payload' => json_encode([
                    'id' => 'pl-meet',
                    'title' => 'Point fournisseur',
                    'date' => now()->setTime(10, 0)->toIso8601String(),
                    'type' => 'meeting',
                    'relatedId' => null,
                    'companyId' => 'co-test',
                ]),
                'title' => 'Point fournisseur',
                'event_at' => now()->setTime(10, 0),
                'type' => 'meeting',
                'related_id' => null,
                'warehouse_id' => null,
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'pl-task',
                'payload' => json_encode([
                    'id' => 'pl-task',
                    'title' => 'Relancer tournée',
                    'date' => now()->setTime(15, 0)->toIso8601String(),
                    'type' => 'task',
                    'relatedId' => 'tsk-1',
                    'companyId' => 'co-test',
                ]),
                'title' => 'Relancer tournée',
                'event_at' => now()->setTime(15, 0),
                'type' => 'task',
                'related_id' => 'tsk-1',
                'warehouse_id' => null,
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('deliveries')->insert([
            'id' => 'dlv-plan',
            'payload' => json_encode([
                'id' => 'dlv-plan',
                'client' => 'Client Casa',
                'scheduledAt' => now()->setTime(11, 0)->toIso8601String(),
                'status' => 'planned',
                'companyId' => 'co-test',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_board_scopes_types_by_role_and_counts_today(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $response = $this->getJson('/api/planning/board');
        $response->assertOk();
        $response->assertJsonPath('counts.today', 2);
        $types = collect($response->json('events'))->pluck('type')->unique()->sort()->values();
        $this->assertEquals(['delivery', 'task'], $types->all());
        $this->assertFalse(collect($response->json('events'))->contains(fn ($e) => ($e['type'] ?? '') === 'meeting'));
    }

    public function test_driver_sees_deliveries_only(): void
    {
        $this->actingAs($this->driver, 'api');
        $response = $this->getJson('/api/planning/board');
        $response->assertOk();
        $types = collect($response->json('events'))->pluck('type')->unique()->values();
        $this->assertEquals(['delivery'], $types->all());
        $this->assertGreaterThanOrEqual(1, $response->json('counts.today'));
    }

    public function test_type_filter_limits_day_events(): void
    {
        $this->actingAs($this->dispatcher, 'api');
        $day = now()->toDateString();
        $response = $this->getJson('/api/planning/board?day='.$day.'&type=task');
        $response->assertOk();
        $this->assertTrue(collect($response->json('dayEvents'))->every(fn ($e) => ($e['type'] ?? '') === 'task'));
        $this->assertSame('task', $response->json('type'));
    }
}
