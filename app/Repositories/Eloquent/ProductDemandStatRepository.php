<?php

namespace App\Repositories\Eloquent;

use App\Models\ProductDemandStat;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ProductDemandStatRepository
{
    public function find(string $productId, string $warehouseId): ?array
    {
        return ProductDemandStat::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first()
            ?->document();
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return ProductDemandStat::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->map(fn (ProductDemandStat $row) => $row->document())
            ->values();
    }

    /**
     * Indexe les statistiques par "productId|warehouseId" pour éviter les N+1 dans le moteur.
     *
     * @param  list<string>  $warehouseIds
     * @return Collection<string, array>
     */
    public function keyedForWarehouses(array $warehouseIds): Collection
    {
        return $this->forWarehouses($warehouseIds)
            ->keyBy(fn (array $row) => $row['productId'].'|'.$row['warehouseId']);
    }

    public function upsert(array $data): array
    {
        $model = ProductDemandStat::query()
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->first()
            ?? new ProductDemandStat(['id' => Uid::make('dst')]);

        $model->fill($data)->save();

        return $model->document();
    }
}
