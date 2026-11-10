<?php

namespace App\Repositories\Eloquent;

use App\Models\Alert;
use Illuminate\Support\Collection;

class AlertRepository extends DocumentRepository
{
    protected string $modelClass = Alert::class;

    protected string $idPrefix = 'al';

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return Alert::query()
            ->where(function ($query) use ($warehouseIds) {
                $query->whereIn('warehouse_id', $warehouseIds)->orWhereNull('warehouse_id');
            })
            ->orderByDesc('alerted_at')
            ->get()
            ->map(fn (Alert $model) => $model->document())
            ->values();
    }
}
