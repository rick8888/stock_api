<?php

namespace App\Repositories\Eloquent;

use App\Models\SupplierProduct;
use App\Support\Uid;
use Illuminate\Support\Collection;

class SupplierProductRepository
{
    public function find(string $supplierId, string $productId): ?array
    {
        return SupplierProduct::query()
            ->where('supplier_id', $supplierId)
            ->where('product_id', $productId)
            ->first()
            ?->document();
    }

    /** @return Collection<int, array> */
    public function forProduct(string $productId): Collection
    {
        return SupplierProduct::query()
            ->where('product_id', $productId)
            ->where('active', true)
            ->get()
            ->map(fn (SupplierProduct $row) => $row->document())
            ->values();
    }

    /**
     * Conditions d'achat actives indexées par "supplierId|productId".
     *
     * @return Collection<string, array>
     */
    public function keyedActive(): Collection
    {
        return SupplierProduct::query()
            ->where('active', true)
            ->get()
            ->map(fn (SupplierProduct $row) => $row->document())
            ->keyBy(fn (array $row) => $row['supplierId'].'|'.$row['productId']);
    }

    public function upsert(array $data): array
    {
        $model = SupplierProduct::query()
            ->where('supplier_id', $data['supplier_id'])
            ->where('product_id', $data['product_id'])
            ->first()
            ?? new SupplierProduct(['id' => Uid::make('spp')]);

        $model->fill($data)->save();

        return $model->document();
    }
}
