<?php

namespace App\Repositories\Eloquent;

use App\Models\StockPlacement;
use Illuminate\Support\Collection;

class StockPlacementRepository extends DocumentRepository
{
    protected string $modelClass = StockPlacement::class;

    protected string $idPrefix = 'plc';

    public function forWarehouse(?string $warehouseId = null, ?string $productId = null): Collection
    {
        $query = StockPlacement::query();
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($productId) {
            $query->where('product_id', $productId);
        }

        $items = $query->get()->map(fn (StockPlacement $model) => $model->document())->values();
        if ($items->isNotEmpty()) {
            return $items;
        }

        if (! $warehouseId && ! $productId) {
            return $items;
        }

        // Legacy payload-only rows.
        return $this->all()
            ->when($warehouseId, fn (Collection $rows) => $rows->filter(fn (array $row) => ($row['warehouseId'] ?? '') === $warehouseId))
            ->when($productId, fn (Collection $rows) => $rows->filter(fn (array $row) => ($row['productId'] ?? '') === $productId))
            ->values();
    }

    public function findLine(string $productId, string $warehouseId, string $locationId, ?string $lotId = null): ?array
    {
        $query = StockPlacement::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId);

        if ($lotId === null) {
            $query->whereNull('lot_id');
        } else {
            $query->where('lot_id', $lotId);
        }

        $model = $query->first();
        if ($model) {
            return $model->document();
        }

        return $this->forWarehouse($warehouseId, $productId)->first(
            fn (array $row) => ($row['locationId'] ?? '') === $locationId
                && ($row['lotId'] ?? null) === $lotId,
        );
    }
}
