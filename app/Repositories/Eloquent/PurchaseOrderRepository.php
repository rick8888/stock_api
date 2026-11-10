<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\PurchaseOrder;
use App\Support\Uid;
use Illuminate\Support\Collection;

class PurchaseOrderRepository extends DocumentRepository
{
    protected string $modelClass = PurchaseOrder::class;

    protected string $idPrefix = 'po';

    public function all(): Collection
    {
        return PurchaseOrder::query()
            ->with('items')
            ->orderByDesc('ordered_at')
            ->get()
            ->map(fn (PurchaseOrder $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return PurchaseOrder::query()->with('items')->find($id)?->document();
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var PurchaseOrder $model */
        $model = PurchaseOrder::query()->find($id) ?? new PurchaseOrder(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingItems();
        $model->load('items');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var PurchaseOrder|null $model */
        $model = PurchaseOrder::query()->with('items')->find($id);
        if (! $model) {
            throw new DomainException($message);
        }
        $model->mergeDocument($patch)->save();
        $model->flushPendingItems();
        $model->load('items');

        return $model->document();
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return PurchaseOrder::query()
            ->with('items')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('ordered_at')
            ->get()
            ->map(fn (PurchaseOrder $model) => $model->document())
            ->values();
    }

    public function countAll(): int
    {
        return PurchaseOrder::query()->count();
    }
}
