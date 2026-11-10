<?php

namespace App\Repositories\Eloquent;

use App\Models\Product;
use Illuminate\Support\Collection;

class ProductRepository extends DocumentRepository
{
    protected string $modelClass = Product::class;

    protected string $idPrefix = 'prd';

    /** @param list<string> $ids */
    public function findMany(array $ids): Collection
    {
        if (! $ids) {
            return collect();
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (Product $model) => $model->document())
            ->values();
    }

    public function forCompany(string $companyId): Collection
    {
        return Product::query()
            ->where('company_id', $companyId)
            ->get()
            ->map(fn (Product $model) => $model->document())
            ->values();
    }
}
