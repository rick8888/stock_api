<?php

namespace App\Repositories\Eloquent;

use App\Models\Vehicle;
use Illuminate\Support\Collection;

class VehicleRepository extends DocumentRepository
{
    protected string $modelClass = Vehicle::class;

    protected string $idPrefix = 'veh';

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return Vehicle::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->map(fn (Vehicle $model) => $model->document())
            ->values();
    }
}
