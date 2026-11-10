<?php

namespace App\Repositories\Eloquent;

use App\Models\StockLot;
use Illuminate\Support\Collection;

class StockLotRepository extends DocumentRepository
{
    protected string $modelClass = StockLot::class;

    protected string $idPrefix = 'lot';

    public function forProductWarehouse(string $productId, string $warehouseId): Collection
    {
        $items = StockLot::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (StockLot $model) => $model->document())
            ->values();

        if ($items->isNotEmpty()) {
            return $items;
        }

        return $this->all()
            ->filter(fn (array $row) => ($row['productId'] ?? '') === $productId && ($row['warehouseId'] ?? '') === $warehouseId)
            ->sortBy(fn (array $row) => $row['expiryDate'] ?? '9999-12-31')
            ->values();
    }

    public function findByCode(string $productId, string $warehouseId, string $lotCode): ?array
    {
        $model = StockLot::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereRaw('LOWER(lot_code) = ?', [strtolower($lotCode)])
            ->first();

        if ($model) {
            return $model->document();
        }

        return $this->forProductWarehouse($productId, $warehouseId)->first(
            fn (array $row) => strcasecmp((string) ($row['lotCode'] ?? ''), $lotCode) === 0,
        );
    }

    public function forWarehouse(?string $warehouseId = null, ?string $productId = null): Collection
    {
        $query = StockLot::query()->orderBy('expiry_date');
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($productId) {
            $query->where('product_id', $productId);
        }

        $items = $query->get()->map(fn (StockLot $model) => $model->document())->values();
        if ($items->isNotEmpty() || ($warehouseId && $productId)) {
            return $items;
        }

        return $this->all()
            ->when($warehouseId, fn (Collection $rows) => $rows->filter(fn (array $row) => ($row['warehouseId'] ?? '') === $warehouseId))
            ->when($productId, fn (Collection $rows) => $rows->filter(fn (array $row) => ($row['productId'] ?? '') === $productId))
            ->sortBy(fn (array $row) => $row['expiryDate'] ?? '9999-12-31')
            ->values();
    }
}
