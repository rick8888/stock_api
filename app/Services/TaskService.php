<?php

namespace App\Services;

use App\Exceptions\DomainException;
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

    /**
     * Mes tâches (assignées à l’acteur courant), dates démo rebasées.
     */
    public function list(): Collection
    {
        return $this->mine()
            ->map(function (array $task) {
                if (! empty($task['dueAt'])) {
                    $task['dueAt'] = DemoDate::rebase($task['dueAt']);
                }

                return $task;
            })
            ->values();
    }

    /**
     * Board ops pour /tasks : groupes + compteurs calculés serveur.
     *
     * @return array{
     *   tasks: list<array<string, mixed>>,
     *   counts: array{open: int, today: int, overdue: int, done: int},
     *   groups: list<array{id: string, title: string, items: list<array<string, mixed>>}>
     * }
     */
    public function board(): array
    {
        $tasks = $this->list()->all();
        $today = now()->toDateString();

        $open = [];
        $overdue = [];
        $todayItems = [];
        $later = [];
        $done = [];

        foreach ($tasks as $task) {
            $status = $task['status'] ?? 'todo';
            $dueKey = substr((string) ($task['dueAt'] ?? ''), 0, 10);
            if ($status === 'done') {
                $done[] = $task;
                continue;
            }
            $open[] = $task;
            if ($dueKey !== '' && $dueKey < $today) {
                $overdue[] = $task;
            } elseif ($dueKey === $today) {
                $todayItems[] = $task;
            } else {
                $later[] = $task;
            }
        }

        $byDue = fn (array $a, array $b) => $this->compareTasks($a, $b);
        usort($overdue, $byDue);
        usort($todayItems, $byDue);
        usort($later, $byDue);
        usort($done, fn (array $a, array $b) => strcmp((string) ($b['dueAt'] ?? ''), (string) ($a['dueAt'] ?? '')));

        $groups = array_values(array_filter([
            ['id' => 'overdue', 'title' => 'En retard', 'items' => $overdue],
            ['id' => 'today', 'title' => 'Aujourd’hui', 'items' => $todayItems],
            ['id' => 'later', 'title' => 'Plus tard', 'items' => $later],
        ], fn (array $g) => count($g['items']) > 0));

        return [
            'tasks' => $tasks,
            'counts' => [
                'open' => count($open),
                'today' => count($todayItems),
                'overdue' => count($overdue),
                'done' => count($done),
            ],
            'groups' => $groups,
            'done' => $done,
        ];
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $actorId = $this->tenant->actorId();
            $task = $this->tasks->put($this->tenant->stamp([
                'id' => Uid::make('tsk'),
                'title' => $input['title'] ?? 'Nouvelle tâche',
                'description' => $input['description'] ?? '',
                'assigneeId' => $input['assigneeId'] ?? $actorId,
                'dueAt' => $input['dueAt'] ?? now()->setTime(18, 0)->toIso8601String(),
                'status' => $input['status'] ?? 'todo',
                'priority' => $input['priority'] ?? 'medium',
                'relatedType' => $input['relatedType'] ?? null,
                'relatedId' => $input['relatedId'] ?? null,
                'warehouseId' => $input['warehouseId'] ?? ($this->tenant->user()?->warehouse_ids[0] ?? null),
            ]));
            $this->planning->put($this->tenant->stamp([
                'id' => Uid::make('pl'),
                'title' => $task['title'],
                'date' => $task['dueAt'],
                'type' => 'task',
                'relatedId' => $task['id'],
                'warehouseId' => $task['warehouseId'] ?? null,
            ]));
            $this->notifier->notify('Nouvelle tâche', $task['title'], 'info', $task['assigneeId'] ?? null);

            return $task;
        });
    }

    public function update(string $id, array $input): array
    {
        $task = $this->tasks->findOrFail($id, 'Tâche introuvable');
        $this->assertCanMutate($task);

        return $this->tasks->merge($id, $input, 'Tâche introuvable');
    }

    private function mine(): Collection
    {
        $actorId = $this->tenant->actorId();
        $rows = $this->tasks->forAssignee($actorId);

        if ($this->tenant->bypassTenantFilter()) {
            return $rows;
        }

        $companyId = $this->tenant->companyId();

        return $rows->filter(function (array $task) use ($companyId) {
            $taskCompany = $task['companyId'] ?? null;
            if ($taskCompany) {
                return $taskCompany === $companyId;
            }

            return true;
        })->values();
    }

    private function assertCanMutate(array $task): void
    {
        $role = $this->tenant->role();
        if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'MANAGER'], true)) {
            return;
        }
        if (($task['assigneeId'] ?? null) === $this->tenant->actorId()) {
            return;
        }

        throw new DomainException('Cette tâche ne vous est pas assignée.', 403);
    }

    /** @param  array<string, mixed>  $a
     *  @param  array<string, mixed>  $b */
    private function compareTasks(array $a, array $b): int
    {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        $pa = $rank[$a['priority'] ?? 'medium'] ?? 1;
        $pb = $rank[$b['priority'] ?? 'medium'] ?? 1;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return strcmp((string) ($a['dueAt'] ?? ''), (string) ($b['dueAt'] ?? ''));
    }
}
