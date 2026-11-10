<?php

namespace App\Services\Replenishment;

/**
 * Calcul d'une proposition de réapprovisionnement pour un couple article / entrepôt.
 *
 * Volontairement sans dépendance : toutes les entrées sont résolues en amont par
 * ReplenishmentService, ce qui rend le calcul vérifiable sans base de données.
 */
class ReplenishmentCalculator
{
    public const POLICY_ROP = 'rop';

    public const POLICY_MIN_MAX = 'min_max';

    /** Périodicité de passation de commande retenue par défaut, en jours. */
    public const DEFAULT_REVIEW_PERIOD_DAYS = 7;

    /**
     * @param  array{
     *     policy?: string,
     *     avgDailyDemand?: float,
     *     demandStdDev?: float,
     *     sampleDays?: int,
     *     leadTimeDays?: float,
     *     leadTimeStdDev?: float,
     *     serviceLevel?: float,
     *     safetyStockOverride?: int|null,
     *     minStock?: int,
     *     maxStock?: int|null,
     *     reviewPeriodDays?: int|null,
     *     available?: int,
     *     inTransit?: int,
     *     pendingNeed?: int,
     *     minOrderQty?: int|null,
     *     orderMultiple?: int|null,
     *     unit?: string
     * }  $input
     * @return array{
     *     safetyStock: int, reorderPoint: int, targetLevel: int, stockPosition: int,
     *     rawQuantity: int, suggestedQuantity: int, coverDays: float|null, risk: string,
     *     shouldOrder: bool, reasons: list<string>
     * }
     */
    public static function evaluate(array $input): array
    {
        $policy = $input['policy'] ?? self::POLICY_ROP;
        $avgDemand = max(0.0, (float) ($input['avgDailyDemand'] ?? 0));
        $demandStdDev = max(0.0, (float) ($input['demandStdDev'] ?? 0));
        $sampleDays = max(0, (int) ($input['sampleDays'] ?? 0));
        $leadTime = max(0.0, (float) ($input['leadTimeDays'] ?? 0));
        $leadTimeStdDev = max(0.0, (float) ($input['leadTimeStdDev'] ?? 0));
        $serviceLevel = (float) ($input['serviceLevel'] ?? ServiceLevel::DEFAULT);
        $minStock = max(0, (int) ($input['minStock'] ?? 0));
        $maxStock = isset($input['maxStock']) && $input['maxStock'] !== null ? max(0, (int) $input['maxStock']) : null;
        $available = (int) ($input['available'] ?? 0);
        $inTransit = max(0, (int) ($input['inTransit'] ?? 0));
        $pendingNeed = max(0, (int) ($input['pendingNeed'] ?? 0));
        $minOrderQty = isset($input['minOrderQty']) && $input['minOrderQty'] !== null ? max(0, (int) $input['minOrderQty']) : null;
        $orderMultiple = isset($input['orderMultiple']) && $input['orderMultiple'] !== null ? max(0, (int) $input['orderMultiple']) : null;
        $unit = (string) ($input['unit'] ?? 'unité');
        $reviewPeriod = $input['reviewPeriodDays'] ?? null;
        $reviewPeriod = $reviewPeriod === null ? self::DEFAULT_REVIEW_PERIOD_DAYS : max(0, (int) $reviewPeriod);

        $reasons = [];

        // Écart-type de la demande pendant le délai. Le second terme capture le risque
        // apporté par l'irrégularité du fournisseur lui-même.
        $sigmaLeadTime = sqrt(($leadTime * $demandStdDev ** 2) + ($avgDemand ** 2 * $leadTimeStdDev ** 2));
        $z = ServiceLevel::zScore($serviceLevel);
        $computedSafety = (int) ceil($z * $sigmaLeadTime);

        $override = $input['safetyStockOverride'] ?? null;
        if ($policy === self::POLICY_MIN_MAX) {
            $safetyStock = $minStock;
        } elseif ($override !== null) {
            $safetyStock = max(0, (int) $override);
            $reasons[] = "Stock de sécurité imposé manuellement : {$safetyStock} {$unit}";
        } else {
            $safetyStock = $computedSafety;
            $reasons[] = sprintf(
                'Stock de sécurité %d %s = z %.2f (service %.0f %%) × écart-type sur délai %.1f',
                $safetyStock,
                $unit,
                $z,
                $serviceLevel * 100,
                $sigmaLeadTime,
            );
        }

        if ($policy === self::POLICY_MIN_MAX) {
            $reorderPoint = $minStock;
            $targetLevel = $maxStock ?? ($minStock + (int) ceil($avgDemand * $reviewPeriod));
            $reasons[] = "Politique min/max : seuil {$reorderPoint}, cible {$targetLevel} {$unit}";
        } else {
            $reorderPoint = (int) ceil($avgDemand * $leadTime) + $safetyStock;
            $targetLevel = $reorderPoint + (int) ceil($avgDemand * $reviewPeriod);
            $reasons[] = sprintf(
                'Point de commande %d %s = demande %.2f/j × délai %.1f j + sécurité %d',
                $reorderPoint,
                $unit,
                $avgDemand,
                $leadTime,
                $safetyStock,
            );

            // Le seuil saisi par l'exploitant reste un plancher : on ne descend jamais
            // sous une consigne manuelle sans le dire.
            if ($minStock > $reorderPoint) {
                $reasons[] = "Seuil manuel plus élevé que le point de commande calculé : plancher à {$minStock} {$unit}";
                $reorderPoint = $minStock;
                $targetLevel = max($targetLevel, $reorderPoint);
            }
        }

        if ($maxStock !== null && $maxStock > 0) {
            if ($maxStock < $reorderPoint) {
                $reasons[] = "Incohérence de paramétrage : stock maximum {$maxStock} inférieur au point de commande {$reorderPoint}";
            } else {
                $targetLevel = min($targetLevel, $maxStock);
            }
        }
        $targetLevel = max($targetLevel, $reorderPoint);

        $stockPosition = $available + $inTransit;
        // Les demandes validées non encore servies consomment la position de stock.
        $netPosition = $stockPosition - $pendingNeed;
        $shouldOrder = $netPosition <= $reorderPoint;

        $rawQuantity = max(0, $targetLevel - $netPosition);
        $quantity = $shouldOrder ? $rawQuantity : 0;

        if ($quantity > 0 && $minOrderQty !== null && $minOrderQty > 0 && $quantity < $minOrderQty) {
            $reasons[] = "Quantité relevée à la commande minimum fournisseur : {$minOrderQty} {$unit}";
            $quantity = $minOrderQty;
        }

        if ($quantity > 0 && $orderMultiple !== null && $orderMultiple > 1) {
            $rounded = (int) (ceil($quantity / $orderMultiple) * $orderMultiple);
            if ($rounded !== $quantity) {
                $reasons[] = "Arrondi au conditionnement de {$orderMultiple} {$unit} : {$quantity} → {$rounded}";
                $quantity = $rounded;
            }
        }

        if ($maxStock !== null && $maxStock > 0 && $quantity > 0 && ($netPosition + $quantity) > $maxStock) {
            $reasons[] = sprintf(
                'Attention : les contraintes fournisseur portent le stock à %d, au-delà du maximum %d',
                $netPosition + $quantity,
                $maxStock,
            );
        }

        $coverDays = $avgDemand > 0 ? round($available / $avgDemand, 1) : null;

        if ($available < $minStock && $minStock > 0) {
            $reasons[] = "Stock disponible sous le seuil ({$available} / {$minStock} {$unit})";
        }
        if ($pendingNeed > 0) {
            $reasons[] = "Demandes validées à servir : {$pendingNeed} {$unit}";
        }
        if ($inTransit > 0) {
            $reasons[] = "Déjà commandé et attendu dans le délai : {$inTransit} {$unit}";
        }
        if ($coverDays !== null) {
            $reasons[] = "Couverture actuelle : {$coverDays} jours";
        }
        if ($avgDemand <= 0) {
            $reasons[] = 'Aucune sortie observée sur la période : proposition fondée sur le seuil manuel';
        } elseif ($sampleDays > 0 && $sampleDays < DemandForecastService::MIN_SAMPLE_DAYS) {
            $reasons[] = "Historique court ({$sampleDays} jours) : prévision à confirmer";
        }

        return [
            'safetyStock' => $safetyStock,
            'reorderPoint' => $reorderPoint,
            'targetLevel' => $targetLevel,
            'stockPosition' => $stockPosition,
            'rawQuantity' => $rawQuantity,
            'suggestedQuantity' => $quantity,
            'coverDays' => $coverDays,
            'risk' => self::risk($available, $netPosition, $safetyStock, $reorderPoint, $coverDays, $leadTime),
            'shouldOrder' => $shouldOrder && $quantity > 0,
            'reasons' => $reasons,
        ];
    }

    private static function risk(
        int $available,
        int $netPosition,
        int $safetyStock,
        int $reorderPoint,
        ?float $coverDays,
        float $leadTime,
    ): string {
        if ($available <= 0) {
            return 'high';
        }
        if ($netPosition <= $safetyStock) {
            return 'high';
        }
        // La couverture ne tient pas jusqu'à la prochaine réception possible.
        if ($coverDays !== null && $leadTime > 0 && $coverDays <= $leadTime) {
            return 'high';
        }
        if ($netPosition <= $reorderPoint) {
            return 'medium';
        }

        return 'low';
    }
}
