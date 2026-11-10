<?php

namespace App\Repositories\Eloquent;

use App\Models\Driver;
use Illuminate\Support\Collection;

class DriverRepository extends DocumentRepository
{
    protected string $modelClass = Driver::class;

    protected string $idPrefix = 'drv';

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        $items = Driver::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->map(fn (Driver $model) => $model->document())
            ->values();

        if ($items->isNotEmpty()) {
            return $items;
        }

        // Drivers without warehouse_id remain visible via legacy filter.
        return $this->all()->filter(function (array $row) use ($warehouseIds) {
            $warehouseId = $row['warehouseId'] ?? null;
            if (! $warehouseId) {
                return true;
            }

            return in_array($warehouseId, $warehouseIds, true);
        })->values();
    }
}
