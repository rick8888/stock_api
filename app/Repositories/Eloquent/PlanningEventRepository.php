<?php

namespace App\Repositories\Eloquent;

use App\Models\PlanningEvent;
use Illuminate\Support\Collection;

class PlanningEventRepository extends DocumentRepository
{
    protected string $modelClass = PlanningEvent::class;

    protected string $idPrefix = 'pl';

    public function forCompany(?string $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return PlanningEvent::query()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->orderBy('event_at')
            ->get()
            ->map(fn (PlanningEvent $model) => $model->document())
            ->values();
    }

    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return PlanningEvent::query()
            ->where(function ($query) use ($warehouseIds) {
                $query->whereIn('warehouse_id', $warehouseIds)->orWhereNull('warehouse_id');
            })
            ->orderBy('event_at')
            ->get()
            ->map(fn (PlanningEvent $model) => $model->document())
            ->values();
    }
}
