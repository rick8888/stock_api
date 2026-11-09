<?php

namespace App\Services;

use App\Repositories\Eloquent\PlanningEventRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(
        private readonly TaskItemRepository $tasks,
        private readonly PlanningEventRepository $planning,
        private readonly OpsNotifier $notifier,
    ) {}

    public function list(): Collection
    {
        return $this->tasks->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $task = $this->tasks->put([
                'id' => Uid::make('tsk'),
                ...$input,
            ]);
            $this->planning->put([
                'id' => Uid::make('pl'),
                'title' => $task['title'],
                'date' => $task['dueAt'],
                'type' => 'task',
                'relatedId' => $task['id'],
            ]);
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
