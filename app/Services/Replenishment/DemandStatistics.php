<?php

namespace App\Services\Replenishment;

/**
 * Statistiques descriptives d'une série de consommations journalières.
 *
 * Les jours sans sortie comptent comme des zéros : les ignorer surestimerait
 * la demande moyenne d'un article à rotation lente et sous-estimerait sa variabilité.
 */
class DemandStatistics
{
    /**
     * @param  array<string, float>  $dailyTotals  consommation indexée par date (Y-m-d)
     * @param  int  $sampleDays  nombre de jours couverts par la fenêtre, zéros inclus
     * @return array{avgDailyDemand: float, demandStdDev: float, totalIssued: float, nonzeroDays: int, sampleDays: int}
     */
    public static function fromDailyTotals(array $dailyTotals, int $sampleDays): array
    {
        $sampleDays = max(0, $sampleDays);
        $total = array_sum($dailyTotals);
        $nonzeroDays = count(array_filter($dailyTotals, static fn (float $qty) => $qty > 0));

        if ($sampleDays === 0) {
            return [
                'avgDailyDemand' => 0.0,
                'demandStdDev' => 0.0,
                'totalIssued' => 0.0,
                'nonzeroDays' => 0,
                'sampleDays' => 0,
            ];
        }

        $mean = $total / $sampleDays;

        // Écart-type d'échantillon (n-1) : on estime la variabilité d'une population
        // à partir d'une fenêtre d'observation, pas de la population entière.
        $stdDev = 0.0;
        if ($sampleDays > 1) {
            $sumSquares = 0.0;
            foreach ($dailyTotals as $qty) {
                $sumSquares += ($qty - $mean) ** 2;
            }
            // Les jours à zéro absents du tableau contribuent chacun pour mean².
            $zeroDays = $sampleDays - count($dailyTotals);
            $sumSquares += $zeroDays * ($mean ** 2);
            $stdDev = sqrt($sumSquares / ($sampleDays - 1));
        }

        return [
            'avgDailyDemand' => round($mean, 4),
            'demandStdDev' => round($stdDev, 4),
            'totalIssued' => $total,
            'nonzeroDays' => $nonzeroDays,
            'sampleDays' => $sampleDays,
        ];
    }
}
