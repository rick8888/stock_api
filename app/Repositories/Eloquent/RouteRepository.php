<?php

namespace App\Repositories\Eloquent;

use App\Models\Route;
use Illuminate\Support\Collection;

class RouteRepository extends DocumentRepository
{
    protected string $modelClass = Route::class;

    protected string $idPrefix = 'rt';

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return Route::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('route_date')
            ->get()
            ->map(fn (Route $model) => $model->document())
            ->values();
    }

    public function forDate(string $date, ?string $warehouseId = null): Collection
    {
        $query = Route::query()->whereDate('route_date', $date);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->get()->map(fn (Route $model) => $model->document())->values();
    }
}
