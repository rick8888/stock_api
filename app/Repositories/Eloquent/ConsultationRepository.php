<?php

namespace App\Repositories\Eloquent;

use App\Exceptions\DomainException;
use App\Models\Consultation;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ConsultationRepository extends DocumentRepository
{
    protected string $modelClass = Consultation::class;

    protected string $idPrefix = 'cs';

    public function all(): Collection
    {
        return Consultation::query()
            ->with('items')
            ->orderByDesc('consulted_at')
            ->get()
            ->map(fn (Consultation $model) => $model->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return Consultation::query()->with('items')->find($id)?->document();
    }

    public function put(array $doc): array
    {
        $id = $doc['id'] ?? Uid::make($this->idPrefix);
        $doc['id'] = $id;
        /** @var Consultation $model */
        $model = Consultation::query()->find($id) ?? new Consultation(['id' => $id]);
        $model->fillDocument($doc)->save();
        $model->flushPendingItems();
        $model->load('items');

        return $model->document();
    }

    public function merge(string $id, array $patch, string $message = 'Introuvable'): array
    {
        /** @var Consultation|null $model */
        $model = Consultation::query()->with('items')->find($id);
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

        return Consultation::query()
            ->with('items')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('consulted_at')
            ->get()
            ->map(fn (Consultation $model) => $model->document())
            ->values();
    }

    public function countAll(): int
    {
        return Consultation::query()->count();
    }
}
