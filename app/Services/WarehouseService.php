<?php

namespace App\Services;

use App\Models\Company;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly ProductRepository $products,
        private readonly StockItemRepository $stock,
        private readonly Tenant $tenant,
        private readonly EntitlementService $entitlements,
    ) {}

    public function list(): Collection
    {
        return $this->tenant->filterWarehouses($this->warehouses->all());
    }

    public function get(string $id): ?array
    {
        $warehouse = $this->warehouses->find($id);
        if (! $warehouse) {
            return null;
        }
        $this->tenant->assertWarehouseAccess($id);

        return $warehouse;
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            if (empty($input['companyId']) && $this->tenant->companyId()) {
                $input['companyId'] = $this->tenant->companyId();
            }
            if (! empty($input['companyId'])) {
                $this->entitlements->assertCanAddWarehouse($input['companyId']);
            }
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
            $products = $this->tenant->filterProducts($this->products->all());
            foreach ($products as $product) {
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
        $this->tenant->assertWarehouseAccess($id);
        $this->warehouses->findOrFail($id, 'Entrepôt introuvable');

        return $this->warehouses->merge($id, $input, 'Entrepôt introuvable');
    }
}
