<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\SupplierProductRepository;
use App\Repositories\Eloquent\SupplierRepository;
use App\Support\Tenant;

/**
 * Offres fournisseurs pour commander une (ou plusieurs) DA — calcul serveur.
 */
class ProcurementSourcingService
{
    private const PRICE_FACTOR = [
        'sup-atlas' => 1.0,
        'sup-abc' => 1.06,
        'sup-maghreb' => 0.97,
        'sup-global' => 1.03,
        'sup-nord' => 0.91,
    ];

    public function __construct(
        private readonly ProcurementBoardService $board,
        private readonly ProductRepository $products,
        private readonly SupplierRepository $suppliers,
        private readonly SupplierProductRepository $catalog,
        private readonly SupplierScoreService $scores,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @param  list<string>  $requestIds
     * @return array{requestIds: list<string>, offers: list<array<string, mixed>>}
     */
    public function offers(array $requestIds): array
    {
        $requestIds = array_values(array_filter(array_map('strval', $requestIds)));
        if (! $requestIds) {
            return ['requestIds' => [], 'offers' => []];
        }

        $overviews = [];
        foreach ($this->board->requestOverviewsPublic() as $row) {
            $id = (string) ($row['request']['id'] ?? '');
            if (in_array($id, $requestIds, true)) {
                $overviews[] = $row;
            }
        }
        if (! $overviews) {
            return ['requestIds' => $requestIds, 'offers' => []];
        }

        $merged = $this->mergeOverviews($overviews);
        $products = $this->products->all()->keyBy('id');
        $suppliers = $this->scopedSuppliers();
        $catalog = $this->scopedCatalog();

        return [
            'requestIds' => $requestIds,
            'offers' => $this->buildOffers($merged, $products, $suppliers, $catalog),
        ];
    }

    /**
     * Brouillon d’OA à partir d’une offre — quantités / montant calculés ici, pas au front.
     *
     * @param  list<string>  $requestIds
     * @return array{
     *   requestIds: list<string>,
     *   warehouseId: string,
     *   supplierId: string,
     *   items: list<array{productId: string, quantity: int}>,
     *   amount: float,
     *   leadTimeDays: int
     * }
     */
    public function draftForSupplier(array $requestIds, string $supplierId): array
    {
        $requestIds = array_values(array_unique(array_filter(array_map('strval', $requestIds))));
        $supplierId = (string) $supplierId;
        if (! $requestIds || $supplierId === '') {
            throw new DomainException('Demande ou fournisseur manquant.');
        }

        $overviews = [];
        foreach ($this->board->requestOverviewsPublic() as $row) {
            $id = (string) ($row['request']['id'] ?? '');
            if (in_array($id, $requestIds, true)) {
                $overviews[] = $row;
            }
        }
        if (! $overviews) {
            throw new DomainException('Demande introuvable');
        }

        $warehouses = [];
        foreach ($overviews as $overview) {
            $warehouses[(string) ($overview['request']['warehouseId'] ?? '')] = true;
        }
        if (count($warehouses) !== 1) {
            throw new DomainException('Regrouper uniquement des demandes du même entrepôt');
        }
        $warehouseId = (string) array_key_first($warehouses);

        $merged = $this->mergeOverviews($overviews);
        $products = $this->products->all()->keyBy('id');
        $suppliers = $this->scopedSuppliers();
        $catalog = $this->scopedCatalog();
        $offers = $this->buildOffers($merged, $products, $suppliers, $catalog);
        $offer = collect($offers)->first(fn (array $row) => ($row['supplierId'] ?? '') === $supplierId);
        if (! is_array($offer)) {
            throw new DomainException('Offre fournisseur introuvable');
        }

        $items = [];
        foreach ($merged['lines'] ?? [] as $line) {
            $productId = (string) ($line['productId'] ?? '');
            if ($productId === '') {
                continue;
            }
            $base = $this->quantityToOrder($line);
            $terms = $catalog->get($supplierId.'|'.$productId);
            $quantity = $this->applyPurchaseTerms($base, is_array($terms) ? $terms : null);
            if ($quantity > 0) {
                $items[] = ['productId' => $productId, 'quantity' => $quantity];
            }
        }
        if (! $items) {
            throw new DomainException('Stock suffisant — pas d’ordre d’achat');
        }

        return [
            'requestIds' => $requestIds,
            'warehouseId' => $warehouseId,
            'supplierId' => $supplierId,
            'items' => $items,
            'amount' => (float) ($offer['amount'] ?? 0),
            'leadTimeDays' => (int) ($offer['leadTimeDays'] ?? 4),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $overviews
     * @return array<string, mixed>
     */
    private function mergeOverviews(array $overviews): array
    {
        if (count($overviews) === 1) {
            return $overviews[0];
        }
        $linesMap = [];
        foreach ($overviews as $overview) {
            foreach ($overview['lines'] ?? [] as $line) {
                $productId = (string) ($line['productId'] ?? '');
                if ($productId === '') {
                    continue;
                }
                if (! isset($linesMap[$productId])) {
                    $linesMap[$productId] = $line;
                    continue;
                }
                $linesMap[$productId]['quantity'] = (int) $linesMap[$productId]['quantity'] + (int) ($line['quantity'] ?? 0);
                $linesMap[$productId]['amount'] = ((float) $linesMap[$productId]['unitPrice']) * ((int) $linesMap[$productId]['quantity']);
            }
        }
        $lines = array_values($linesMap);
        $base = $overviews[0];
        $base['lines'] = $lines;
        $base['total'] = array_sum(array_column($lines, 'amount'));
        $base['code'] = implode(' · ', array_map(fn (array $o) => (string) ($o['code'] ?? ''), $overviews));

        return $base;
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $products
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $suppliers
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $catalog
     * @return list<array<string, mixed>>
     */
    private function buildOffers($overview, $products, $suppliers, $catalog): array
    {
        $needed = [];
        foreach ($overview['lines'] ?? [] as $line) {
            $qty = $this->quantityToOrder($line);
            if ($qty > 0) {
                $needed[] = ['line' => $line, 'quantity' => $qty];
            }
        }
        if (! $needed) {
            return [];
        }

        $preferredIds = [];
        foreach ($needed as $item) {
            $product = $products->get($item['line']['productId'] ?? '');
            if (is_array($product) && ! empty($product['supplierId'])) {
                $preferredIds[] = (string) $product['supplierId'];
            }
        }
        $preferredId = $preferredIds[0] ?? null;

        $active = $suppliers->filter(fn (array $s) => ($s['status'] ?? '') === 'active')->values();
        $scoreById = $this->scores->scoreMany($active);

        $ranked = $active->sort(function (array $a, array $b) use ($scoreById) {
            $sa = (int) ($scoreById->get($a['id'] ?? '')['reliability'] ?? 0);
            $sb = (int) ($scoreById->get($b['id'] ?? '')['reliability'] ?? 0);

            return ($sb <=> $sa)
                ?: ((int) ($a['leadTimeDays'] ?? 0) <=> (int) ($b['leadTimeDays'] ?? 0));
        })->values();

        $picked = [];
        $preferred = $active->first(fn (array $s) => ($s['id'] ?? '') === $preferredId);
        if (is_array($preferred)) {
            $picked[] = $preferred;
        }
        foreach ($ranked as $supplier) {
            if (count($picked) >= 3) {
                break;
            }
            if (! collect($picked)->contains(fn (array $s) => ($s['id'] ?? '') === ($supplier['id'] ?? ''))) {
                $picked[] = $supplier;
            }
        }

        $offers = [];
        foreach ($picked as $supplier) {
            $supplierId = (string) ($supplier['id'] ?? '');
            $performance = $scoreById->get($supplierId, $this->scores->score($supplier));
            $reliability = (int) ($performance['reliability'] ?? 0);
            $factor = self::PRICE_FACTOR[$supplierId] ?? 1.0;
            $lines = [];
            foreach ($needed as $item) {
                $terms = $catalog->get($supplierId.'|'.($item['line']['productId'] ?? ''));
                $lines[] = [
                    'line' => $item['line'],
                    'quantity' => $this->applyPurchaseTerms($item['quantity'], is_array($terms) ? $terms : null),
                ];
            }
            $amount = 0.0;
            $quantity = 0;
            foreach ($lines as $item) {
                $amount += ((float) ($item['line']['unitPrice'] ?? 0)) * $factor * ((int) $item['quantity']);
                $quantity += (int) $item['quantity'];
            }
            $reasons = [];
            if ($supplierId === $preferredId) {
                $reasons[] = 'Fournisseur habituel de l’article';
            }
            foreach ($performance['reasons'] ?? [] as $reason) {
                $reasons[] = $reason;
            }
            $reasons[] = 'Délai annoncé '.((int) ($supplier['leadTimeDays'] ?? 0)).' j';
            $adjusted = 0;
            foreach ($lines as $item) {
                $baseQty = 0;
                foreach ($needed as $row) {
                    if (($row['line']['productId'] ?? '') === ($item['line']['productId'] ?? '')) {
                        $baseQty = (int) $row['quantity'];
                        break;
                    }
                }
                if ((int) $item['quantity'] !== $baseQty) {
                    $adjusted++;
                }
            }
            if ($adjusted) {
                $reasons[] = 'Quantité ajustée au minimum/colisage sur '.$adjusted.' article(s)';
            }
            $score = $reliability * 0.5
                + max(0, 10 - (int) ($supplier['leadTimeDays'] ?? 0)) * 4
                + max(0, 1.15 - $factor) * 80;
            $offers[] = [
                'supplierId' => $supplierId,
                'supplierName' => (string) ($supplier['name'] ?? $supplierId),
                'city' => (string) ($supplier['city'] ?? ''),
                'reliability' => $reliability,
                'declaredReliability' => (int) ($performance['declaredReliability'] ?? $reliability),
                'onTimeRate' => $performance['onTimeRate'] ?? null,
                'orderSampleSize' => (int) ($performance['orderSampleSize'] ?? 0),
                'leadTimeDays' => (int) ($supplier['leadTimeDays'] ?? 0),
                'unitPrice' => $quantity ? $amount / $quantity : 0,
                'amount' => $amount,
                'quantity' => $quantity,
                'preferred' => $supplierId === $preferredId,
                'recommended' => false,
                'score' => $score,
                'reasons' => $reasons,
            ];
        }

        if ($offers) {
            $fastest = min(array_column($offers, 'leadTimeDays'));
            $cheapest = min(array_column($offers, 'amount'));
            $mostReliable = max(array_column($offers, 'reliability'));
            foreach ($offers as &$offer) {
                if ($offer['leadTimeDays'] === $fastest) {
                    $offer['reasons'][] = 'Délai le plus court';
                }
                if ($offer['amount'] === $cheapest) {
                    $offer['reasons'][] = 'Meilleur prix';
                }
                if ($offer['reliability'] === $mostReliable) {
                    $offer['reasons'][] = 'Meilleure fiabilité observée';
                }
            }
            unset($offer);
            usort($offers, fn (array $a, array $b) => $b['score'] <=> $a['score']);
            $offers[0]['recommended'] = true;
            $offers[0]['reasons'][] = 'Meilleur score global (prix · délai · ponctualité)';
        }

        return $offers;
    }

    /** @param  array<string, mixed>  $line */
    private function quantityToOrder(array $line): int
    {
        $net = max(0, (int) ($line['quantity'] ?? 0) - (int) ($line['available'] ?? 0) - (int) ($line['inTransit'] ?? 0));

        return $this->applyPurchaseTerms($net, null);
    }

    /** @param  array<string, mixed>|null  $terms */
    private function applyPurchaseTerms(int $quantity, ?array $terms): int
    {
        if ($quantity <= 0) {
            return 0;
        }
        $result = $quantity;
        $min = isset($terms['minOrderQty']) ? (int) $terms['minOrderQty'] : 0;
        if ($min > 0 && $result < $min) {
            $result = $min;
        }
        $multiple = isset($terms['orderMultiple']) ? (int) $terms['orderMultiple'] : 0;
        if ($multiple > 1) {
            $result = (int) (ceil($result / $multiple) * $multiple);
        }

        return $result;
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function scopedSuppliers()
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->suppliers->all()->values();
        }

        return $this->suppliers->forCompany($this->tenant->companyId())->values();
    }

    /** @return \Illuminate\Support\Collection<string, array<string, mixed>> */
    private function scopedCatalog()
    {
        $rows = $this->tenant->bypassTenantFilter()
            ? $this->catalog->all()
            : $this->catalog->forCompany($this->tenant->companyId());

        return $rows->keyBy(fn (array $row) => ($row['supplierId'] ?? '').'|'.($row['productId'] ?? ''));
    }
}
