<?php

namespace App\Console\Commands;

use App\Services\Replenishment\DemandForecastService;
use Illuminate\Console\Command;

class ComputeDemandForecast extends Command
{
    protected $signature = 'replenishment:compute-demand
        {--warehouse=* : Limiter à un ou plusieurs entrepôts}
        {--window= : Fenêtre d\'observation en jours}';

    protected $description = 'Recalcule la demande moyenne et sa variabilité à partir des sorties de stock';

    public function handle(DemandForecastService $forecast): int
    {
        $warehouses = (array) $this->option('warehouse');
        $window = (int) ($this->option('window') ?: DemandForecastService::DEFAULT_WINDOW_DAYS);

        $stats = $forecast->recompute(array_values(array_filter($warehouses)), $window);

        if ($stats->isEmpty()) {
            $this->warn('Aucune sortie de stock trouvée sur la période : rien à calculer.');

            return self::SUCCESS;
        }

        $thin = $stats->filter(
            fn (array $row) => $row['sampleDays'] < DemandForecastService::MIN_SAMPLE_DAYS
        )->count();

        $this->info("Demande recalculée sur {$stats->count()} couples article/entrepôt (fenêtre {$window} j).");
        if ($thin > 0) {
            $this->warn("{$thin} couples ont moins de ".DemandForecastService::MIN_SAMPLE_DAYS.' jours d’historique : prévision peu fiable.');
        }

        return self::SUCCESS;
    }
}
