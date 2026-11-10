<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\ProcurementRequest;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ProcurementRequestRepository extends DocumentRepository
{
    protected string $modelClass = ProcurementRequest::class;

    protected string $idPrefix = 'pr';

    public function all(): Collection
    {
        return ProcurementRequest::query()
            ->with('items')
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (ProcurementRequest $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return ProcurementRequest::query()->with('items')->find($id)?->document();
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var ProcurementRequest $model */
        $model = ProcurementRequest::query()->find($id) ?? new ProcurementRequest(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingItems();
        $model->load('items');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var ProcurementRequest|null $model */
        $model = ProcurementRequest::query()->with('items')->find($id);
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

        return ProcurementRequest::query()
            ->with('items')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (ProcurementRequest $model) => $model->document())
            ->values();
    }

    public function countAll(): int
    {
        return ProcurementRequest::query()->count();
    }
}
