<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\CategoryRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly WarehouseRepository $warehouses,
        private readonly StockItemRepository $stock,
        private readonly CategoryRepository $categories,
    ) {}

    public function list(): Collection
    {
        return $this->products->all()->filter(fn (array $item) => empty($item['archived']))->values();
    }

    public function categories(): Collection
    {
        return $this->categories->all();
    }

    public function get(string $id): ?array
    {
        $product = $this->products->find($id);
        if (! $product || ! empty($product['archived'])) {
            return null;
        }

        return $product;
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $product = $this->products->put([
                'id' => Uid::make('prd'),
                'archived' => false,
                ...$input,
            ]);
            foreach ($this->warehouses->all() as $warehouse) {
                $this->stock->put([
                    'id' => Uid::make('stk'),
                    'productId' => $product['id'],
                    'warehouseId' => $warehouse['id'],
                    'quantity' => 0,
                    'reserved' => 0,
                    'enabled' => true,
                ]);
            }

            return $product;
        });
    }

    public function update(string $id, array $input): array
    {
        $this->products->findOrFail($id, 'Produit introuvable');

        return $this->products->merge($id, $input, 'Produit introuvable');
    }

    public function archive(string $id): void
    {
        $this->products->merge($id, ['archived' => true], 'Produit introuvable');
    }
}
