<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\Shipment;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ShipmentRepository extends DocumentRepository
{
    protected string $modelClass = Shipment::class;

    protected string $idPrefix = 'shp';

    public function all(): Collection
    {
        return Shipment::query()
            ->with('lines')
            ->orderByDesc('shipped_at')
            ->get()
            ->map(fn (Shipment $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return Shipment::query()->with('lines')->find($id)?->document();
    }

    public function findForUpdate(string $id): ?array
    {
        $model = Shipment::query()->whereKey($id)->lockForUpdate()->first();
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
        /** @var Shipment $model */
        $model = Shipment::query()->find($id) ?? new Shipment(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingLines();
        $model->load('lines');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var Shipment|null $model */
        $model = Shipment::query()->with('lines')->find($id);
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

        return Shipment::query()
            ->with('lines')
            ->where(function ($query) use ($warehouseIds) {
                $query->whereIn('origin_warehouse_id', $warehouseIds)
                    ->orWhereIn('destination_warehouse_id', $warehouseIds);
            })
            ->orderByDesc('shipped_at')
            ->get()
            ->map(fn (Shipment $model) => $model->document())
            ->values();
    }

    public function countAll(): int
    {
        return Shipment::query()->count();
    }
}
