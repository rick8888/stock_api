<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\InventorySession;
use App\Support\Uid;
use Illuminate\Support\Collection;

class InventorySessionRepository extends DocumentRepository
{
    protected string $modelClass = InventorySession::class;

    protected string $idPrefix = 'inv';

    public function all(): Collection
    {
        return InventorySession::query()
            ->with('lines')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (InventorySession $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return InventorySession::query()->with('lines')->find($id)?->document();
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var InventorySession $model */
        $model = InventorySession::query()->find($id) ?? new InventorySession(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingLines();
        $model->load('lines');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var InventorySession|null $model */
        $model = InventorySession::query()->with('lines')->find($id);
        if (! $model) {
            throw new DomainException($message);
        }
        $model->mergeDocument($patch)->save();
        $model->flushPendingLines();
        $model->load('lines');

        return $model->document();
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return InventorySession::query()
            ->with('lines')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (InventorySession $model) => $model->document())
            ->values();
    }

    public function openForWarehouse(string $warehouseId): ?array
    {
        return InventorySession::query()
            ->with('lines')
            ->where('warehouse_id', $warehouseId)
            ->where('status', '!=', 'completed')
            ->orderByDesc('started_at')
            ->first()
            ?->document();
    }
}
