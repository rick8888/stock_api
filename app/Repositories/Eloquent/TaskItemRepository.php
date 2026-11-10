<?php

namespace App\Repositories\Eloquent;

use App\Models\TaskItem;
use Illuminate\Support\Collection;

class TaskItemRepository extends DocumentRepository
{
    protected string $modelClass = TaskItem::class;

    protected string $idPrefix = 'tsk';

    public function forAssignee(?string $assigneeId = null): Collection
    {
        $query = TaskItem::query()->orderBy('due_at');
        if ($assigneeId) {
            $query->where('assignee_id', $assigneeId);
        }

        return $query->get()->map(fn (TaskItem $model) => $model->document())->values();
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return TaskItem::query()
            ->where(function ($query) use ($warehouseIds) {
                $query->whereIn('warehouse_id', $warehouseIds)->orWhereNull('warehouse_id');
            })
            ->orderBy('due_at')
            ->get()
            ->map(fn (TaskItem $model) => $model->document())
            ->values();
    }
}
