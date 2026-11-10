<?php

namespace App\Repositories\Eloquent;

use App\Models\WarehouseLocation;
use Illuminate\Support\Collection;

class WarehouseLocationRepository extends DocumentRepository
{
    protected string $modelClass = WarehouseLocation::class;

    protected string $idPrefix = 'loc';

    public function forWarehouse(string $warehouseId): Collection
    {
        $items = WarehouseLocation::query()
            ->where('warehouse_id', $warehouseId)
            ->orderBy('code')
            ->get()
            ->map(fn (WarehouseLocation $model) => $model->document())
            ->values();

        if ($items->isNotEmpty()) {
            return $items;
        }

        return $this->all()
            ->filter(fn (array $row) => ($row['warehouseId'] ?? '') === $warehouseId)
            ->sortBy(fn (array $row) => $row['code'] ?? '')
            ->values();
    }

    public function findByCode(string $warehouseId, string $code): ?array
    {
        $model = WarehouseLocation::query()
            ->where('warehouse_id', $warehouseId)
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->first();

        if ($model) {
            return $model->document();
        }

        return $this->forWarehouse($warehouseId)->first(
            fn (array $row) => strcasecmp((string) ($row['code'] ?? ''), $code) === 0,
        );
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return WarehouseLocation::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderBy('code')
            ->get()
            ->map(fn (WarehouseLocation $model) => $model->document())
            ->values();
    }
}
