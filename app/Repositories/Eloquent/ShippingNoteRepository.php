<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\DocumentModel;
use App\Models\ShippingNote;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ShippingNoteRepository extends DocumentRepository
{
    protected string $modelClass = ShippingNote::class;

    protected string $idPrefix = 'bl';

    public function all(): Collection
    {
        return ShippingNote::query()
            ->with('lines')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ShippingNote $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        $model = ShippingNote::query()->with('lines')->find($id);

        return $model?->document();
    }

    public function findForUpdate(string $id): ?array
    {
        $model = ShippingNote::query()->whereKey($id)->lockForUpdate()->first();
        if (! $model) {
            return null;
        }
        $model->load('lines');

        return $model->document();
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var ShippingNote $model */
        $model = ShippingNote::query()->find($id) ?? new ShippingNote(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingLines();
        $model->load('lines');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var ShippingNote|null $model */
        $model = ShippingNote::query()->with('lines')->find($id);
        if (! $model) {
            throw new DomainException($message);
        }
        $model->mergeDocument($patch)->save();
        $model->flushPendingLines();
        $model->load('lines');

        return $model->document();
    }

    public function forWarehouse(string $warehouseId): Collection
    {
        $items = ShippingNote::query()
            ->with('lines')
            ->where('warehouse_id', $warehouseId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ShippingNote $model) => $model->document())
            ->values();

        if ($items->isNotEmpty()) {
            return $items;
        }

        return $this->all()->filter(fn (array $row) => ($row['warehouseId'] ?? '') === $warehouseId)->values();
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return ShippingNote::query()
            ->with('lines')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ShippingNote $model) => $model->document())
            ->values();
    }

    public function nextCodeSequence(): int
    {
        $max = ShippingNote::query()
            ->whereNotNull('code')
            ->get(['code'])
            ->map(fn (ShippingNote $note) => (int) substr((string) $note->code, -5))
            ->filter()
            ->max();

        if ($max) {
            return (int) $max;
        }

        return (int) $this->all()
            ->map(fn (array $note) => (int) substr((string) ($note['code'] ?? '0'), -5))
            ->filter()
            ->max();
    }
}
