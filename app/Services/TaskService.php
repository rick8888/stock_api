<?php

namespace App\Services;

use App\Repositories\Eloquent\PlanningEventRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use App\Support\DemoDate;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private readonly TaskItemRepository $tasks,
        private readonly PlanningEventRepository $planning,
        private readonly OpsNotifier $notifier,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        $rows = $this->tenant->bypassTenantFilter()
            ? $this->tasks->all()
            : $this->tasks->forWarehouses($this->tenant->companyWarehouseIds());

        return $rows
            ->map(function (array $task) {
                if (! empty($task['dueAt'])) {
                    $task['dueAt'] = DemoDate::rebase($task['dueAt']);
                }

                return $task;
            })
            ->values();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $task = $this->tasks->put($this->tenant->stamp([
                'id' => Uid::make('tsk'),
                ...$input,
            ]));
            $this->planning->put($this->tenant->stamp([
                'id' => Uid::make('pl'),
                'title' => $task['title'],
                'date' => $task['dueAt'],
                'type' => 'task',
                'relatedId' => $task['id'],
                'warehouseId' => $task['warehouseId'] ?? null,
            ]));
            $this->notifier->notify('Nouvelle tâche', $task['title']);

            return $task;
        });
    }

    public function update(string $id, array $input): array
    {
        $this->tasks->findOrFail($id, 'Tâche introuvable');

        return $this->tasks->merge($id, $input, 'Tâche introuvable');
    }
}
