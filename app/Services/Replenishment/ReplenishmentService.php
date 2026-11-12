<?php

namespace App\Services\Replenishment;

use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\ProductDemandStatRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\ReplenishmentSuggestionRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\SupplierProductRepository;
use App\Repositories\Eloquent\SupplierRepository;
use App\Repositories\Eloquent\SupplierWarehouseTermRepository;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Produit les propositions de réapprovisionnement d'un entrepôt.
 *
 * Ce service résout les paramètres (demande observée, conditions fournisseur, couverture
 * déjà engagée) et délègue l'arithmétique à ReplenishmentCalculator.
 */
class ReplenishmentService
{
    /**
     * Statuts de commande considérés comme couverture à venir. Les brouillons sont inclus
     * pour éviter de reproposer une quantité qu'un acheteur a déjà saisie.
     */
    public const OPEN_ORDER_STATUSES = ['draft', 'sent', 'confirmed', 'partial', 'delayed'];

    /** Statuts de demande d'achat qui engagent du stock à servir. */
    public const PENDING_REQUEST_STATUSES = ['submitted', 'approved'];

    public const DEFAULT_LEAD_TIME_DAYS = 5;

    public function __construct(
        private readonly StockItemRepository $stock,
        private readonly ProductRepository $products,
        private readonly SupplierRepository $suppliers,
        private readonly ProductDemandStatRepository $demandStats,
        private readonly SupplierProductRepository $supplierProducts,
        private readonly SupplierWarehouseTermRepository $supplierTerms,
        private readonly PurchaseOrderRepository $orders,
        private readonly ProcurementRequestRepository $requests,
        private readonly ReplenishmentSuggestionRepository $suggestions,
        private readonly Tenant $tenant,
    ) {}

    /** Propositions déjà calculées, dans le périmètre de l'utilisateur courant. */
    public function list(?string $warehouseId = null): Collection
    {
        $warehouseIds = $this->scopedWarehouseIds($warehouseId);

        return $this->suggestions->forWarehouses($warehouseIds);
    }

    /**
     * Recalcule et persiste les propositions d'un entrepôt.
     *
     * @return Collection<int, array>
     */
    public function compute(string $warehouseId): Collection
    {
        $rows = $this->evaluateWarehouse($warehouseId)
            ->filter(fn (array $row) => $row['suggestedQuantity'] > 0)
            ->values();

        return $this->suggestions->replaceForWarehouse(
            $warehouseId,
            $rows->map(fn (array $row) => $this->toRecord($row))->all(),
        );
    }

    /**
     * Évalue chaque couple article / entrepôt sans rien persister.
     * Exposé séparément pour permettre une simulation avant écriture.
     *
     * @return Collection<int, array>
     */
    public function evaluateWarehouse(
        string $warehouseId,
        ?float $serviceLevelOverride = null,
        ?float $leadTimeOverride = null,
    ): Collection {
        $this->tenant->assertWarehouseAccess($warehouseId);

        $products = $this->products->all()->keyBy('id');
        $suppliers = $this->suppliers->all()->keyBy('id');
        $demand = $this->demandStats->keyedForWarehouses([$warehouseId]);
        $catalog = $this->supplierProducts->keyedActive();
        $terms = $this->supplierTerms->keyedActive();
        $incoming = $this->incomingByProduct($warehouseId);
        $pending = $this->pendingNeedByProduct($warehouseId);

        return $this->stock->forWarehouses([$warehouseId])
            ->filter(function (array $item) use ($products) {
                if (($item['enabled'] ?? true) === false) {
                    return false;
                }
                $product = $products->get($item['productId'] ?? '');

                return $product && empty($product['archived']);
            })
            ->map(fn (array $item) => $this->evaluateLine(
                $item,
                $products->get($item['productId']),
                $suppliers,
                $demand,
                $catalog,
                $terms,
                $incoming,
                $pending,
                $warehouseId,
                $serviceLevelOverride,
                $leadTimeOverride,
            ))
            ->values();
    }

    /** @return array<string, mixed> */
    private function evaluateLine(
        array $item,
        array $product,
        Collection $suppliers,
        Collection $demand,
        Collection $catalog,
        Collection $terms,
        Collection $incoming,
        Collection $pending,
        string $warehouseId,
        ?float $serviceLevelOverride = null,
        ?float $leadTimeOverride = null,
    ): array {
        $productId = (string) $item['productId'];
        $available = max(0, (int) ($item['quantity'] ?? 0) - (int) ($item['reserved'] ?? 0));

        $supplierId = (string) ($product['supplierId'] ?? '');
        $supplier = $supplierId ? $suppliers->get($supplierId) : null;
        $catalogLine = $supplierId ? $catalog->get($supplierId.'|'.$productId) : null;
        $term = $supplierId ? $terms->get($supplierId.'|'.$warehouseId) : null;

        // Le délai le plus spécifique gagne : conditions par entrepôt, puis par article,
        // puis délai général du fournisseur. Override what-if si fourni.
        $leadTime = $leadTimeOverride ?? (float) (
            $term['leadTimeDays']
            ?? $catalogLine['leadTimeDays']
            ?? $supplier['leadTimeDays']
            ?? self::DEFAULT_LEAD_TIME_DAYS
        );
        $leadTimeStdDev = (float) ($term['leadTimeStdDev'] ?? $supplier['leadTimeStdDev'] ?? 0);

        $stats = $demand->get($productId.'|'.$warehouseId);
        $reviewPeriod = $product['reviewPeriodDays'] ?? null;

        $incomingLines = $incoming->get($productId, collect());
        $horizon = CarbonImmutable::today()->addDays(
            (int) ceil($leadTime + ($reviewPeriod ?? ReplenishmentCalculator::DEFAULT_REVIEW_PERIOD_DAYS))
        );
        $withinHorizon = 0;
        $beyondHorizon = 0;
        foreach ($incomingLines as $line) {
            // Une commande sans date attendue est comptée comme couverture : l'exclure
            // ferait reproposer une quantité déjà engagée.
            if ($line['expectedDate'] === null || $line['expectedDate']->lessThanOrEqualTo($horizon)) {
                $withinHorizon += $line['quantity'];
            } else {
                $beyondHorizon += $line['quantity'];
            }
        }

        $serviceLevel = $serviceLevelOverride ?? ServiceLevel::resolve(
            isset($product['serviceLevel']) ? (float) $product['serviceLevel'] : null,
            $product['abcClass'] ?? null,
        );

        $result = ReplenishmentCalculator::evaluate([
            'policy' => $product['replenishmentPolicy'] ?? ReplenishmentCalculator::POLICY_ROP,
            'avgDailyDemand' => (float) ($stats['avgDailyDemand'] ?? 0),
            'demandStdDev' => (float) ($stats['demandStdDev'] ?? 0),
            'sampleDays' => (int) ($stats['sampleDays'] ?? 0),
            'leadTimeDays' => $leadTime,
            'leadTimeStdDev' => $leadTimeStdDev,
            'serviceLevel' => $serviceLevel,
            'safetyStockOverride' => $product['safetyStock'] ?? null,
            'minStock' => (int) ($product['minStock'] ?? 0),
            'maxStock' => $product['maxStock'] ?? null,
            'reviewPeriodDays' => $reviewPeriod,
            'available' => $available,
            'inTransit' => $withinHorizon,
            'pendingNeed' => (int) $pending->get($productId, 0),
            'minOrderQty' => $catalogLine['minOrderQty'] ?? null,
            'orderMultiple' => $catalogLine['orderMultiple'] ?? null,
            'unit' => (string) ($product['unit'] ?? 'unité'),
        ]);

        $reasons = $result['reasons'];
        if ($beyondHorizon > 0) {
            $reasons[] = "Commande attendue hors horizon, non déduite : {$beyondHorizon}";
        }
        if (! $supplier) {
            $reasons[] = 'Aucun fournisseur rattaché à cet article : délai par défaut appliqué';
        }
        if ($supplier && ! $catalogLine) {
            $reasons[] = 'Pas de conditions d’achat renseignées : ni minimum de commande ni conditionnement appliqué';
        }

        $unitPrice = $catalogLine['unitPrice'] ?? $product['price'] ?? null;

        return [
            ...$result,
            'reasons' => $reasons,
            'productId' => $productId,
            'warehouseId' => $warehouseId,
            'supplierId' => $supplierId ?: null,
            'policy' => $product['replenishmentPolicy'] ?? ReplenishmentCalculator::POLICY_ROP,
            'avgDailyDemand' => (float) ($stats['avgDailyDemand'] ?? 0),
            'demandStdDev' => (float) ($stats['demandStdDev'] ?? 0),
            'sampleDays' => (int) ($stats['sampleDays'] ?? 0),
            'leadTimeDays' => $leadTime,
            'leadTimeStdDev' => $leadTimeStdDev,
            'serviceLevel' => $serviceLevel,
            'available' => $available,
            'inTransit' => $withinHorizon,
            'pendingNeed' => (int) $pending->get($productId, 0),
            'minOrderQty' => $catalogLine['minOrderQty'] ?? null,
            'orderMultiple' => $catalogLine['orderMultiple'] ?? null,
            'unitPrice' => $unitPrice === null ? null : (float) $unitPrice,
        ];
    }

    /** @return array<string, mixed> */
    private function toRecord(array $row): array
    {
        $unitPrice = $row['unitPrice'];

        return [
            'product_id' => $row['productId'],
            'warehouse_id' => $row['warehouseId'],
            'supplier_id' => $row['supplierId'],
            'policy' => $row['policy'],
            'avg_daily_demand' => $row['avgDailyDemand'],
            'demand_std_dev' => $row['demandStdDev'],
            'lead_time_days' => $row['leadTimeDays'],
            'lead_time_std_dev' => $row['leadTimeStdDev'],
            'service_level' => $row['serviceLevel'],
            'safety_stock' => $row['safetyStock'],
            'reorder_point' => $row['reorderPoint'],
            'target_level' => $row['targetLevel'],
            'available' => $row['available'],
            'in_transit' => $row['inTransit'],
            'pending_need' => $row['pendingNeed'],
            'raw_quantity' => $row['rawQuantity'],
            'suggested_quantity' => $row['suggestedQuantity'],
            'min_order_qty' => $row['minOrderQty'],
            'order_multiple' => $row['orderMultiple'],
            'unit_price' => $unitPrice,
            'estimated_cost' => $unitPrice === null ? null : round($unitPrice * $row['suggestedQuantity'], 2),
            'risk' => $row['risk'],
            'reasons' => $row['reasons'],
            'computed_at' => now(),
            'company_id' => $this->tenant->companyId(),
        ];
    }

    /**
     * Quantités commandées non encore reçues, avec leur date attendue.
     *
     * @return Collection<string, Collection<int, array{quantity: int, expectedDate: CarbonImmutable|null}>>
     */
    private function incomingByProduct(string $warehouseId): Collection
    {
        $lines = collect();

        foreach ($this->orders->forWarehouses([$warehouseId]) as $order) {
            if (! in_array($order['status'] ?? '', self::OPEN_ORDER_STATUSES, true)) {
                continue;
            }

            $expected = null;
            if (! empty($order['expectedDate'])) {
                try {
                    $expected = CarbonImmutable::parse((string) $order['expectedDate'])->startOfDay();
                } catch (\Throwable) {
                    $expected = null;
                }
            }

            foreach ($order['items'] ?? [] as $item) {
                if (empty($item['productId'])) {
                    continue;
                }
                $lines->push([
                    'productId' => (string) $item['productId'],
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'expectedDate' => $expected,
                ]);
            }
        }

        return $lines->groupBy('productId');
    }

    /** @return Collection<string, int> */
    private function pendingNeedByProduct(string $warehouseId): Collection
    {
        $totals = [];

        foreach ($this->requests->forWarehouses([$warehouseId]) as $request) {
            if (! in_array($request['status'] ?? '', self::PENDING_REQUEST_STATUSES, true)) {
                continue;
            }
            foreach ($request['items'] ?? [] as $item) {
                if (empty($item['productId'])) {
                    continue;
                }
                $productId = (string) $item['productId'];
                $totals[$productId] = ($totals[$productId] ?? 0) + (int) ($item['quantity'] ?? 0);
            }
        }

        return collect($totals);
    }

    /** @return list<string> */
    private function scopedWarehouseIds(?string $warehouseId): array
    {
        $allowed = $this->tenant->companyWarehouseIds();
        if ($warehouseId === null || $warehouseId === '') {
            return $allowed;
        }
        $this->tenant->assertWarehouseAccess($warehouseId);

        return [$warehouseId];
    }
}
