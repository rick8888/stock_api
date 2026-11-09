<?php

namespace App\Services;

use App\Models\Company;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly ProductRepository $products,
        private readonly StockItemRepository $stock,
    ) {}

    public function list(): Collection
    {
        return $this->warehouses->all();
    }

    public function get(string $id): ?array
    {
        return $this->warehouses->find($id);
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $warehouse = $this->warehouses->put([
                'id' => Uid::make('wh'),
                ...$input,
            ]);
            if (! empty($warehouse['companyId'])) {
                $company = Company::query()->find($warehouse['companyId']);
                if ($company) {
                    $company->increment('warehouses_count');
                }
            }
            foreach ($this->products->all() as $product) {
                $this->stock->put([
                    'id' => Uid::make('stk'),
                    'productId' => $product['id'],
                    'warehouseId' => $warehouse['id'],
                    'quantity' => 0,
                    'reserved' => 0,
                    'enabled' => true,
                ]);
            }

            return $warehouse;
        });
    }

    public function update(string $id, array $input): array
    {
        $this->warehouses->findOrFail($id, 'Entrepôt introuvable');

        return $this->warehouses->merge($id, $input, 'Entrepôt introuvable');
    }
}
