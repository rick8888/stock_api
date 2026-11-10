<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\Delivery;
use App\Support\Uid;
use Illuminate\Support\Collection;

class DeliveryRepository extends DocumentRepository
{
    protected string $modelClass = Delivery::class;

    protected string $idPrefix = 'dlv';

    public function all(): Collection
    {
        return Delivery::query()
            ->with('lines')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Delivery $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return Delivery::query()->with('lines')->find($id)?->document();
    }

    public function findForUpdate(string $id): ?array
    {
        $model = Delivery::query()->whereKey($id)->lockForUpdate()->first();
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
        /** @var Delivery $model */
        $model = Delivery::query()->find($id) ?? new Delivery(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingLines();
        $model->load('lines');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var Delivery|null $model */
        $model = Delivery::query()->with('lines')->find($id);
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

        return Delivery::query()
            ->with('lines')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Delivery $model) => $model->document())
            ->values();
    }

    public function forDriver(string $driverId): Collection
    {
        return Delivery::query()
            ->with('lines')
            ->where('driver_id', $driverId)
            ->orderBy('scheduled_at')
            ->orderBy('sequence')
            ->get()
            ->map(fn (Delivery $model) => $model->document())
            ->values();
    }

    public function countAll(): int
    {
        return Delivery::query()->count();
    }
}
