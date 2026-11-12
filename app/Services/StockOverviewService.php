<?php

namespace App\Services;

use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\SupplierRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Overview /stock — santé, transit, recommandations et compteurs calculés serveur.
 */
class StockOverviewService
{
    private const OPEN_ORDERS = ['draft', 'sent', 'confirmed', 'partial', 'delayed'];

    private const OPEN_REQUESTS = ['submitted', 'approved'];

    public function __construct(
        private readonly StockService $stock,
        private readonly ProductRepository $products,
        private readonly WarehouseRepository $warehouses,
        private readonly PurchaseOrderRepository $orders,
        private readonly SupplierRepository $suppliers,
        private readonly ProcurementRequestRepository $requests,
        private readonly Tenant $tenant,
        private readonly UserDirectory $users,
    ) {}

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   counts: array{all: int, critical: int, watch: int, on_order: int, normal: int},
     *   closedWarehouseIds: list<string>,
     *   today: string
     * }
     */
    public function board(): array
    {
        $rows = $this->rows();
        $counts = [
            'all' => count($rows),
            'critical' => 0,
            'watch' => 0,
            'on_order' => 0,
            'normal' => 0,
        ];
        foreach ($rows as $row) {
            $status = $row['status'] ?? 'normal';
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $today = now()->toDateString();
        $closed = [];
        foreach ($this->scopedWarehouseIds() as $warehouseId) {
            $stamp = $warehouseId.'|'.$today;
            if ($this->users->auditHas('Clôture de journée', $stamp)) {
                $closed[] = $warehouseId;
            }
        }

        return [
            'rows' => $rows,
            'counts' => $counts,
            'closedWarehouseIds' => $closed,
            'today' => $today,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $products = $this->products->all()->keyBy('id');
        $warehouses = $this->warehouses->all()->keyBy('id');
        $suppliers = $this->suppliers->all()->keyBy('id');
        $orders = $this->orders->all();
        $requests = $this->requests->all();

        $rows = [];
        foreach ($this->stock->list() as $item) {
            if (($item['enabled'] ?? true) === false) {
                continue;
            }
            $product = $products->get($item['productId'] ?? '');
            if (! is_array($product) || ! empty($product['archived'])) {
                continue;
            }
            $warehouseId = (string) ($item['warehouseId'] ?? '');
            $warehouse = $warehouses->get($warehouseId);
            $supplierId = (string) ($product['supplierId'] ?? '');
            $supplier = $suppliers->get($supplierId);

            $inTransit = $this->inTransit($orders, (string) $product['id'], $warehouseId);
            $physical = (int) ($item['quantity'] ?? 0);
            $reserved = (int) ($item['reserved'] ?? 0);
            $available = max(0, $physical - $reserved);
            $daily = (float) ($product['dailyDemand'] ?? 0);
            $days = $daily > 0 ? round($available / $daily, 1) : 99.0;
            $minStock = (int) ($product['minStock'] ?? 0);
            $onOrder = $inTransit > 0;
            $status = $this->health($available, $minStock, $days, $onOrder);
            $leadTimeDays = (int) (is_array($supplier) ? ($supplier['leadTimeDays'] ?? 5) : 5);
            $weeklyDemand = (int) round($daily * 7);
            $unit = (string) ($product['unit'] ?? 'unité');
            // Board léger : pas de base64 / galeries (sinon payload multi‑Mo et « Réseau indisponible »).
            $imageUrl = $this->lightImageUrl($product);

            $row = [
                'id' => (string) ($item['id'] ?? ''),
                'productId' => (string) $product['id'],
                'warehouseId' => $warehouseId,
                'productName' => (string) ($product['name'] ?? $product['id']),
                'sku' => (string) ($product['sku'] ?? ''),
                'unit' => $unit,
                'categoryId' => (string) ($product['categoryId'] ?? ''),
                'warehouseName' => (string) (is_array($warehouse) ? ($warehouse['name'] ?? $warehouseId) : $warehouseId),
                'physical' => $physical,
                'reserved' => $reserved,
                'available' => $available,
                'inTransit' => $inTransit,
                'minStock' => $minStock,
                'maxStock' => (int) ($product['maxStock'] ?? ($minStock * 4)),
                'days' => $days,
                'weeklyDemand' => $weeklyDemand,
                'leadTimeDays' => $leadTimeDays,
                'supplierId' => $supplierId,
                'supplierName' => (string) (is_array($supplier) ? ($supplier['name'] ?? '') : ''),
                'status' => $status,
                'flags' => ['onOrder' => $onOrder],
                'imageUrl' => $imageUrl,
                'images' => $imageUrl ? [$imageUrl] : [],
                'description' => null,
                'barcode' => $product['barcode'] ?? null,
            ];
            $row['recommendation'] = $this->recommend($row, $requests);
            $rows[] = $row;
        }

        usort($rows, function (array $a, array $b) {
            $rank = ['critical' => 0, 'watch' => 1, 'on_order' => 2, 'normal' => 3];

            return ($rank[$a['status'] ?? 'normal'] <=> $rank[$b['status'] ?? 'normal'])
                ?: ($a['days'] <=> $b['days']);
        });

        return $rows;
    }

    /**
     * Risques stock (couverture ≤ 7 j ou sous seuil) — même logique que l’overview.
     *
     * @return list<array<string, mixed>>
     */
    public function risks(): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            $belowMin = $row['available'] < $row['minStock'];
            if ($row['days'] > 7 && ! $belowMin) {
                continue;
            }
            $out[] = [
                'productId' => $row['productId'],
                'warehouseId' => $row['warehouseId'],
                'productName' => $row['productName'],
                'warehouseName' => $row['warehouseName'],
                'quantity' => $row['available'],
                'days' => max(1.0, (float) $row['days']),
                'tone' => $row['status'] === 'critical' ? 'critical' : 'warning',
                'belowMin' => $belowMin,
                'status' => $row['status'],
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row
     *  @param  Collection<int, array<string, mixed>>  $requests
     *  @return array{quantity: int, risk: string, reasons: list<string>}
     */
    private function recommend(array $row, Collection $requests): array
    {
        $daily = ((float) ($row['weeklyDemand'] ?? 0)) / 7;
        $pendingNeed = 0;
        foreach ($requests as $request) {
            if (($request['warehouseId'] ?? '') !== ($row['warehouseId'] ?? '')) {
                continue;
            }
            if (! in_array($request['status'] ?? '', self::OPEN_REQUESTS, true)) {
                continue;
            }
            foreach ($request['items'] ?? [] as $line) {
                if (($line['productId'] ?? '') === ($row['productId'] ?? '')) {
                    $pendingNeed += (int) ($line['quantity'] ?? 0);
                }
            }
        }

        $coverNeed = (int) ceil($daily * (int) ($row['leadTimeDays'] ?? 5));
        $target = (int) ($row['minStock'] ?? 0) + $coverNeed + $pendingNeed;
        $quantity = max(0, $target - (int) ($row['available'] ?? 0) - (int) ($row['inTransit'] ?? 0));
        $unit = (string) ($row['unit'] ?? 'unité');
        $reasons = [];
        if (($row['available'] ?? 0) < ($row['minStock'] ?? 0)) {
            $reasons[] = 'Stock disponible insuffisant ('.$row['available'].' / seuil '.$row['minStock'].')';
        }
        if ($pendingNeed) {
            $reasons[] = 'Demande validée ou soumise : '.$pendingNeed.' '.$unit.'s';
        }
        if ($daily) {
            $reasons[] = 'Consommation moyenne : '.$row['weeklyDemand'].' / semaine';
        }
        $reasons[] = 'Délai fournisseur : '.$row['leadTimeDays'].' jours';
        $reasons[] = 'Stock de sécurité : '.$row['minStock'].' '.$unit.'s';
        if (($row['inTransit'] ?? 0) > 0) {
            $reasons[] = 'Déjà en commande : '.$row['inTransit'].' '.$unit.'s';
        }

        $status = $row['status'] ?? 'normal';

        return [
            'quantity' => $quantity,
            'risk' => $status === 'critical' ? 'high' : ($status === 'watch' ? 'medium' : 'low'),
            'reasons' => $reasons,
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $orders */
    private function inTransit(Collection $orders, string $productId, string $warehouseId): int
    {
        $sum = 0;
        foreach ($orders as $order) {
            if (($order['warehouseId'] ?? '') !== $warehouseId) {
                continue;
            }
            if (! in_array($order['status'] ?? '', self::OPEN_ORDERS, true)) {
                continue;
            }
            foreach ($order['items'] ?? [] as $line) {
                if (($line['productId'] ?? '') === $productId) {
                    $sum += (int) ($line['quantity'] ?? 0);
                }
            }
        }

        return $sum;
    }

    private function health(int $available, int $minStock, float $days, bool $onOrder): string
    {
        if ($available < $minStock * 0.5 || $days <= 3) {
            return 'critical';
        }
        if ($available < $minStock || $days <= 7) {
            return 'watch';
        }
        if ($onOrder) {
            return 'on_order';
        }

        return 'normal';
    }

    /** @return list<string> */
    private function scopedWarehouseIds(): array
    {
        $userScope = array_values(array_filter($this->tenant->user()?->warehouse_ids ?? []));
        if ($userScope) {
            return $userScope;
        }

        return $this->tenant->companyWarehouseIds();
    }

    /** @param  array<string, mixed>  $product */
    private function lightImageUrl(array $product): ?string
    {
        $candidates = [];
        if (! empty($product['imageUrl'])) {
            $candidates[] = (string) $product['imageUrl'];
        }
        foreach ($product['images'] ?? [] as $img) {
            if (is_string($img) && $img !== '') {
                $candidates[] = $img;
            }
        }
        foreach ($candidates as $url) {
            if (str_starts_with($url, 'data:')) {
                continue;
            }
            if (strlen($url) > 500) {
                continue;
            }

            return $url;
        }

        return null;
    }
}
