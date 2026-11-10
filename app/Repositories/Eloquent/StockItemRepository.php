<?php

namespace App\Repositories\Eloquent;

use App\Models\StockItem;
use Illuminate\Support\Collection;

class StockItemRepository extends DocumentRepository
{
    protected string $modelClass = StockItem::class;

    protected string $idPrefix = 'stk';

    public function findByProductWarehouse(string $productId, string $warehouseId): ?array
    {
        $model = StockItem::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($model) {
            return $model->document();
        }

        // Legacy rows not yet backfilled into columns.
        return $this->all()->first(
            fn (array $row) => ($row['productId'] ?? '') === $productId && ($row['warehouseId'] ?? '') === $warehouseId,
        );
    }

    public function findByProductWarehouseForUpdate(string $productId, string $warehouseId): ?array
    {
        $model = StockItem::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($model) {
            return $model->document();
        }

        $fallback = $this->findByProductWarehouse($productId, $warehouseId);
        if (! $fallback) {
            return null;
        }

        return $this->findForUpdate($fallback['id']);
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return StockItem::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->map(fn (StockItem $model) => $model->document())
            ->values();
    }

    /** @param list<string> $warehouseIds */
    public function productIdsInWarehouses(array $warehouseIds): array
    {
        if (! $warehouseIds) {
            return [];
        }

        return StockItem::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id')
            ->filter()
            ->values()
            ->all();
    }
}
