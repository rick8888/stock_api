<?php

namespace App\Repositories\Eloquent;

use App\Models\SupplierWarehouseTerm;
use App\Support\Uid;
use Illuminate\Support\Collection;

class SupplierWarehouseTermRepository
{
    public function find(string $supplierId, string $warehouseId): ?array
    {
        return SupplierWarehouseTerm::query()
            ->where('supplier_id', $supplierId)
            ->where('warehouse_id', $warehouseId)
            ->first()
            ?->document();
    }

    /**
     * Conditions actives indexées par "supplierId|warehouseId".
     *
     * @return Collection<string, array>
     */
    public function keyedActive(): Collection
    {
        return SupplierWarehouseTerm::query()
            ->where('active', true)
            ->get()
            ->map(fn (SupplierWarehouseTerm $row) => $row->document())
            ->keyBy(fn (array $row) => $row['supplierId'].'|'.$row['warehouseId']);
    }

    public function upsert(array $data): array
    {
        $model = SupplierWarehouseTerm::query()
            ->where('supplier_id', $data['supplier_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->first()
            ?? new SupplierWarehouseTerm(['id' => Uid::make('swt')]);

        $model->fill($data)->save();

        return $model->document();
    }
}
