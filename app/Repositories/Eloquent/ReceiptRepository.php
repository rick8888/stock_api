<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\Receipt;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ReceiptRepository extends DocumentRepository
{
    protected string $modelClass = Receipt::class;

    protected string $idPrefix = 'rcp';

    public function all(): Collection
    {
        return Receipt::query()
            ->with('items')
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Receipt $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return Receipt::query()->with('items')->find($id)?->document();
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var Receipt $model */
        $model = Receipt::query()->find($id) ?? new Receipt(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingItems();
        $model->load('items');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var Receipt|null $model */
        $model = Receipt::query()->with('items')->find($id);
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

        return Receipt::query()
            ->with('items')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Receipt $model) => $model->document())
            ->values();
    }

    public function countAll(): int
    {
        return Receipt::query()->count();
    }
}
