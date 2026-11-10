<?php

namespace App\Repositories\Eloquent;

use App\Models\Warehouse;
use Illuminate\Support\Collection;

class WarehouseRepository extends DocumentRepository
{
    protected string $modelClass = Warehouse::class;

    protected string $idPrefix = 'wh';

    public function idsForCompany(string $companyId): array
    {
        return Warehouse::query()
            ->where('company_id', $companyId)
            ->pluck('id')
            ->values()
            ->all();
    }

    public function forCompany(string $companyId): Collection
    {
        return Warehouse::query()
            ->where('company_id', $companyId)
            ->get()
            ->map(fn (Warehouse $model) => $model->document())
            ->values();
    }
}
