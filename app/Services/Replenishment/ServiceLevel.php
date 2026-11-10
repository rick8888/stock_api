<?php

namespace App\Services\Replenishment;

/**
 * Conversion d'un taux de service en coefficient de sécurité (z).
 *
 * z est le quantile de la loi normale centrée réduite : pour un taux de service de 95 %,
 * z ≈ 1,645, ce qui signifie que le stock de sécurité couvre 95 % des aléas de la demande
 * pendant le délai d'approvisionnement.
 */
class ServiceLevel
{
    /** Taux de service retenu quand ni l'article ni sa classe ABC n'en imposent un. */
    public const DEFAULT = 0.95;

    /**
     * Un article de classe A justifie un taux de service plus élevé qu'un article C :
     * c'est l'arbitrage classique entre coût de possession et risque de rupture.
     */
    public const BY_ABC_CLASS = [
        'A' => 0.98,
        'B' => 0.95,
        'C' => 0.90,
    ];

    /**
     * Quantile de la loi normale centrée réduite, par l'approximation rationnelle
     * de Peter Acklam (erreur relative < 1,15e-9 sur l'intervalle utile).
     */
    public static function zScore(float $serviceLevel): float
    {
        // Hors bornes, le z n'a pas de sens : on retombe sur le taux par défaut.
        if ($serviceLevel <= 0.0 || $serviceLevel >= 1.0) {
            $serviceLevel = self::DEFAULT;
        }

        $a = [
            -3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
            1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00,
        ];
        $b = [
            -5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
            6.680131188771972e+01, -1.328068155288572e+01,
        ];
        $c = [
            -7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
            -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00,
        ];
        $d = [
            7.784695709041462e-03, 3.224671290700398e-01,
            2.445134137142996e+00, 3.754408661907416e+00,
        ];

        $pLow = 0.02425;
        $pHigh = 1 - $pLow;
        $p = $serviceLevel;

        if ($p < $pLow) {
            $q = sqrt(-2 * log($p));

            $z = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        } elseif ($p <= $pHigh) {
            $q = $p - 0.5;
            $r = $q * $q;

            $z = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        } else {
            $q = sqrt(-2 * log(1 - $p));

            $z = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        return round($z, 6);
    }

    /** Taux de service applicable à un article, par ordre de priorité décroissant. */
    public static function resolve(?float $productLevel, ?string $abcClass): float
    {
        if ($productLevel !== null && $productLevel > 0.0 && $productLevel < 1.0) {
            return $productLevel;
        }

        $class = strtoupper((string) $abcClass);

        return self::BY_ABC_CLASS[$class] ?? self::DEFAULT;
    }
}
