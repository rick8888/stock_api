<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);

        $this->manager = User::query()->create([
            'id' => 'usr-task',
            'first_name' => 'Sara',
            'last_name' => 'Tasks',
            'email' => 'tasks@test.local',
            'password' => 'demo',
            'role' => 'MANAGER',
            'title' => 'Ops',
            'avatar_hue' => 10,
            'warehouse_ids' => [],
            'product_ids' => [],
            'region_ids' => [],
            'company_id' => 'co-test',
        ]);

        DB::table('tasks')->insert([
            [
                'id' => 'tsk-over',
                'payload' => json_encode([
                    'id' => 'tsk-over',
                    'title' => 'En retard',
                    'assigneeId' => 'usr-task',
                    'dueAt' => now()->subDay()->toIso8601String(),
                    'status' => 'todo',
                    'priority' => 'high',
                    'companyId' => 'co-test',
                ]),
                'title' => 'En retard',
                'assignee_id' => 'usr-task',
                'due_at' => now()->subDay(),
                'status' => 'todo',
                'priority' => 'high',
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'tsk-today',
                'payload' => json_encode([
                    'id' => 'tsk-today',
                    'title' => 'Aujourd’hui',
                    'assigneeId' => 'usr-task',
                    'dueAt' => now()->setTime(17, 0)->toIso8601String(),
                    'status' => 'todo',
                    'priority' => 'medium',
                    'companyId' => 'co-test',
                ]),
                'title' => 'Aujourd’hui',
                'assignee_id' => 'usr-task',
                'due_at' => now()->setTime(17, 0),
                'status' => 'todo',
                'priority' => 'medium',
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'tsk-other',
                'payload' => json_encode([
                    'id' => 'tsk-other',
                    'title' => 'Autre user',
                    'assigneeId' => 'usr-other',
                    'dueAt' => now()->toIso8601String(),
                    'status' => 'todo',
                    'priority' => 'low',
                    'companyId' => 'co-test',
                ]),
                'title' => 'Autre user',
                'assignee_id' => 'usr-other',
                'due_at' => now(),
                'status' => 'todo',
                'priority' => 'low',
                'company_id' => 'co-test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_board_scopes_to_assignee_and_groups(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/tasks/board');
        $response->assertOk();
        $response->assertJsonPath('counts.open', 2);
        $response->assertJsonPath('counts.overdue', 1);
        $response->assertJsonPath('counts.today', 1);
        $ids = collect($response->json('tasks'))->pluck('id');
        $this->assertTrue($ids->contains('tsk-over'));
        $this->assertTrue($ids->contains('tsk-today'));
        $this->assertFalse($ids->contains('tsk-other'));
    }

    public function test_list_is_mine_only(): void
    {
        $this->actingAs($this->manager, 'api');
        $response = $this->getJson('/api/tasks');
        $response->assertOk();
        $payload = $response->json();
        $rows = $payload['data'] ?? $payload;
        $ids = collect($rows)->pluck('id');
        $this->assertCount(2, $ids, 'payload='.json_encode($payload));
        $this->assertFalse($ids->contains('tsk-other'));
    }
}
