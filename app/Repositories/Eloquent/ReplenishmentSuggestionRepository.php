<?php

namespace App\Repositories\Eloquent;

use App\Models\ReplenishmentSuggestion;
use App\Support\Uid;
use Illuminate\Support\Collection;

class ReplenishmentSuggestionRepository
{
    /**
     * Remplace les propositions d'un entrepôt par le résultat du dernier calcul.
     * On ne conserve pas d'historique : une proposition périmée n'a pas de valeur
     * opérationnelle et fausserait la lecture de l'écran.
     *
     * @param  list<array>  $rows
     * @return Collection<int, array>
     */
    public function replaceForWarehouse(string $warehouseId, array $rows): Collection
    {
        ReplenishmentSuggestion::query()->where('warehouse_id', $warehouseId)->delete();

        $saved = collect();
        foreach ($rows as $row) {
            $model = new ReplenishmentSuggestion(['id' => Uid::make('rsg')]);
            $model->fill($row)->save();
            $saved->push($model->document());
        }

        return $saved->values();
    }

    /** @param list<string> $warehouseIds */
    public function forWarehouses(array $warehouseIds): Collection
    {
        if (! $warehouseIds) {
            return collect();
        }

        return ReplenishmentSuggestion::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('suggested_quantity')
            ->get()
            ->map(fn (ReplenishmentSuggestion $row) => $row->document())
            ->values();
    }

    public function find(string $id): ?array
    {
        return ReplenishmentSuggestion::query()->find($id)?->document();
    }
}
