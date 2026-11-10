<?php

namespace App\Services\Replenishment;

use App\Repositories\Eloquent\ProductDemandStatRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dérive la demande moyenne et sa variabilité de l'historique réel des sorties,
 * en remplacement du champ dailyDemand qui était saisi à la main.
 */
class DemandForecastService
{
    public const DEFAULT_WINDOW_DAYS = 90;

    /**
     * En deçà de ce nombre de jours observés, la moyenne reste publiée mais le moteur
     * la signale comme peu fiable plutôt que de la prendre pour argent comptant.
     */
    public const MIN_SAMPLE_DAYS = 14;

    /**
     * Mouvements comptés comme consommation sortante d'un entrepôt.
     * Les transferts sont exclus par défaut : ce sont des déplacements internes, et les
     * compter des deux côtés gonflerait artificiellement la demande du réseau.
     */
    public const DEMAND_TYPES = ['issue'];

    public function __construct(
        private readonly ProductDemandStatRepository $stats,
        private readonly WarehouseRepository $warehouses,
    ) {}

    /**
     * Recalcule et persiste les statistiques de demande.
     *
     * @param  list<string>  $warehouseIds  périmètre ; vide = tous les entrepôts
     * @return Collection<int, array>
     */
    public function recompute(array $warehouseIds = [], int $windowDays = self::DEFAULT_WINDOW_DAYS): Collection
    {
        $windowDays = max(1, $windowDays);
        $end = CarbonImmutable::today();
        $start = $end->subDays($windowDays - 1);

        $daily = $this->dailyTotals($warehouseIds, $start, $end);
        if ($daily->isEmpty()) {
            return collect();
        }

        $firstSeen = $this->firstMovementDates($warehouseIds);
        $companyByWarehouse = $this->warehouses->all()
            ->pluck('companyId', 'id')
            ->filter();

        $results = collect();
        foreach ($daily as $key => $series) {
            [$productId, $warehouseId] = explode('|', $key, 2);

            // La fenêtre ne peut pas être plus large que l'historique disponible :
            // un article référencé il y a 10 jours ne se juge pas sur 90 jours.
            $effectiveStart = $start;
            $first = $firstSeen->get($key);
            if ($first && $first->greaterThan($start)) {
                $effectiveStart = $first;
            }
            $sampleDays = $effectiveStart->diffInDays($end) + 1;

            $stats = DemandStatistics::fromDailyTotals($series, $sampleDays);

            $results->push($this->stats->upsert([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'avg_daily_demand' => $stats['avgDailyDemand'],
                'demand_std_dev' => $stats['demandStdDev'],
                'sample_days' => $stats['sampleDays'],
                'nonzero_days' => $stats['nonzeroDays'],
                'total_issued' => (int) round($stats['totalIssued']),
                'method' => 'moving_average',
                'window_start' => $effectiveStart->toDateString(),
                'window_end' => $end->toDateString(),
                'computed_at' => now(),
                'company_id' => $companyByWarehouse->get($warehouseId),
            ]));
        }

        return $results->values();
    }

    /**
     * Consommation journalière groupée par "productId|warehouseId".
     *
     * @param  list<string>  $warehouseIds
     * @return Collection<string, array<string, float>>
     */
    private function dailyTotals(array $warehouseIds, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $query = DB::table('stock_movements')
            ->selectRaw('product_id, from_warehouse_id, '.$this->dateExpression().' as day, SUM(ABS(quantity)) as qty')
            ->whereIn('type', self::DEMAND_TYPES)
            ->whereNotNull('product_id')
            ->whereNotNull('from_warehouse_id')
            ->whereNotNull('moved_at')
            ->whereBetween('moved_at', [$start->startOfDay(), $end->endOfDay()])
            ->groupBy('product_id', 'from_warehouse_id')
            ->groupByRaw($this->dateExpression());

        if ($warehouseIds) {
            $query->whereIn('from_warehouse_id', $warehouseIds);
        }

        return $query->get()
            ->groupBy(fn ($row) => $row->product_id.'|'.$row->from_warehouse_id)
            ->map(fn (Collection $rows) => $rows
                ->mapWithKeys(fn ($row) => [(string) $row->day => (float) $row->qty])
                ->all());
    }

    /**
     * Date de la toute première sortie observée, hors fenêtre, par couple article/entrepôt.
     *
     * @param  list<string>  $warehouseIds
     * @return Collection<string, CarbonImmutable>
     */
    private function firstMovementDates(array $warehouseIds): Collection
    {
        $query = DB::table('stock_movements')
            ->selectRaw('product_id, from_warehouse_id, MIN(moved_at) as first_at')
            ->whereIn('type', self::DEMAND_TYPES)
            ->whereNotNull('product_id')
            ->whereNotNull('from_warehouse_id')
            ->whereNotNull('moved_at')
            ->groupBy('product_id', 'from_warehouse_id');

        if ($warehouseIds) {
            $query->whereIn('from_warehouse_id', $warehouseIds);
        }

        return $query->get()->mapWithKeys(function ($row) {
            $key = $row->product_id.'|'.$row->from_warehouse_id;

            try {
                return [$key => CarbonImmutable::parse($row->first_at)->startOfDay()];
            } catch (\Throwable) {
                return [$key => null];
            }
        })->filter();
    }

    private function dateExpression(): string
    {
        return DB::getDriverName() === 'sqlite' ? "date(moved_at)" : 'DATE(moved_at)';
    }
}
