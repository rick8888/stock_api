<?php

namespace App\Services;

use App\Repositories\Eloquent\AlertRepository;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\StockLotRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\DemoDate;

class AlertEngine
{
    public function __construct(
        private readonly StockItemRepository $stock,
        private readonly ProductRepository $products,
        private readonly WarehouseRepository $warehouses,
        private readonly PurchaseOrderRepository $orders,
        private readonly DeliveryRepository $deliveries,
        private readonly StockLotRepository $lots,
        private readonly AlertRepository $alerts,
        private readonly OpsNotifier $notifier,
    ) {}

    /**
     * @return array{created: int, skipped: int}
     */
    public function run(): array
    {
        $created = 0;
        $skipped = 0;
        foreach ([
            ...$this->lowStockAlerts(),
            ...$this->delayedOrderAlerts(),
            ...$this->stalledDeliveryAlerts(),
            ...$this->expiringLotAlerts(),
        ] as $alert) {
            if ($this->existsSimilar($alert)) {
                $skipped++;

                continue;
            }
            $this->notifier->alert($alert);
            $created++;
        }

        return compact('created', 'skipped');
    }

    /** @return list<array<string, mixed>> */
    private function lowStockAlerts(): array
    {
        $products = $this->products->all()->keyBy('id');
        $warehouses = $this->warehouses->all()->keyBy('id');
        $out = [];
        foreach ($this->stock->all() as $row) {
            if (($row['enabled'] ?? true) === false) {
                continue;
            }
            $product = $products->get($row['productId'] ?? '');
            if (! $product || ! empty($product['archived'])) {
                continue;
            }
            $min = (int) ($product['minStock'] ?? 0);
            $available = max(0, (int) ($row['quantity'] ?? 0) - (int) ($row['reserved'] ?? 0));
            if ($min <= 0 || $available >= $min) {
                continue;
            }
            $warehouse = $warehouses->get($row['warehouseId'] ?? '');
            $out[] = [
                'title' => 'Stock bas — '.($product['name'] ?? $row['productId']),
                'message' => ($warehouse['name'] ?? $row['warehouseId']).' : '.$available.' dispo. (seuil '.$min.').',
                'severity' => $available <= 0 ? 'critical' : 'warning',
                'domain' => 'stock',
                // relatedId = productId pour deep-link réappro / DA
                'relatedId' => $row['productId'] ?? $row['id'],
                'warehouseId' => $row['warehouseId'] ?? null,
                'companyId' => $warehouse['companyId'] ?? ($product['companyId'] ?? null),
                'roles' => ['WAREHOUSE_MANAGER', 'PROCUREMENT', 'MANAGER'],
                'code' => 'low_stock:'.$row['productId'].':'.$row['warehouseId'],
                'action' => 'replenish',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function delayedOrderAlerts(): array
    {
        $today = now()->toDateString();
        $out = [];
        foreach ($this->orders->all() as $order) {
            $status = $order['status'] ?? '';
            if (in_array($status, ['received', 'cancelled', 'canceled'], true)) {
                continue;
            }
            $expected = $order['expectedDate'] ?? null;
            if (! $expected) {
                continue;
            }
            $expected = substr((string) DemoDate::rebase($expected), 0, 10);
            if ($expected >= $today) {
                continue;
            }
            $out[] = [
                'title' => 'Commande en retard — '.($order['code'] ?? $order['id']),
                'message' => 'Attendue le '.$expected.', toujours au statut '.$status.'.',
                'severity' => 'warning',
                'domain' => 'procurement',
                'relatedId' => $order['id'],
                'warehouseId' => $order['warehouseId'] ?? null,
                'companyId' => $order['companyId'] ?? null,
                'roles' => ['PROCUREMENT', 'MANAGER', 'WAREHOUSE_MANAGER'],
                'code' => 'po_delay:'.$order['id'].':'.$expected,
                'action' => 'receive',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function expiringLotAlerts(): array
    {
        $horizon = now()->addDays(14)->toDateString();
        $today = now()->toDateString();
        $products = $this->products->all()->keyBy('id');
        $warehouses = $this->warehouses->all()->keyBy('id');
        $out = [];
        foreach ($this->lots->all() as $lot) {
            $qty = (int) ($lot['quantity'] ?? 0);
            $expiry = $lot['expiryDate'] ?? null;
            if ($qty <= 0 || ! $expiry) {
                continue;
            }
            $day = substr((string) DemoDate::rebase($expiry), 0, 10);
            if ($day > $horizon) {
                continue;
            }
            $product = $products->get($lot['productId'] ?? '');
            $warehouse = $warehouses->get($lot['warehouseId'] ?? '');
            $expired = $day < $today;
            $out[] = [
                'title' => ($expired ? 'Lot périmé — ' : 'DLC proche — ').($product['name'] ?? $lot['productId'] ?? 'article'),
                'message' => 'Lot '.($lot['lotCode'] ?? $lot['id']).' · '.$qty.' '.($product['unit'] ?? 'u').' · DLC '.$day.' · '.($warehouse['name'] ?? $lot['warehouseId'] ?? ''),
                'severity' => $expired ? 'critical' : 'warning',
                'domain' => 'stock',
                'relatedId' => $lot['productId'] ?? $lot['id'],
                'warehouseId' => $lot['warehouseId'] ?? null,
                'companyId' => $warehouse['companyId'] ?? ($product['companyId'] ?? ($lot['companyId'] ?? null)),
                'roles' => ['WAREHOUSE_MANAGER', 'MANAGER', 'PROCUREMENT'],
                'code' => 'lot_expiry:'.($lot['id'] ?? '').':'.$day,
                'action' => 'stock',
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function stalledDeliveryAlerts(): array
    {
        $today = now()->toDateString();
        $out = [];
        foreach ($this->deliveries->all() as $delivery) {
            $status = $delivery['status'] ?? '';
            if (in_array($status, ['delivered', 'failed'], true)) {
                continue;
            }
            $scheduled = $delivery['scheduledAt'] ?? null;
            if (! $scheduled) {
                continue;
            }
            $day = substr((string) DemoDate::rebase($scheduled), 0, 10);
            // Uniquement les retards calendaires : le bruit « prepared le jour J » noie Today en démo.
            if ($day >= $today) {
                continue;
            }
            if (in_array($status, ['in_transit', 'delivered', 'failed'], true)) {
                continue;
            }
            $out[] = [
                'title' => 'Livraison non démarrée — '.($delivery['code'] ?? $delivery['client'] ?? $delivery['id']),
                'message' => ($delivery['client'] ?? '').' ('.($delivery['city'] ?? '').') prévue le '.$day.', statut '.$status.'.',
                'severity' => 'critical',
                'domain' => 'logistics',
                'relatedId' => $delivery['id'],
                'warehouseId' => $delivery['warehouseId'] ?? null,
                'companyId' => $delivery['companyId'] ?? null,
                'roles' => ['DISPATCHER', 'MANAGER'],
                'code' => 'delivery_stall:'.$delivery['id'].':'.$day,
                'action' => 'dispatch',
            ];
        }

        return $out;
    }

    private function existsSimilar(array $alert): bool
    {
        $code = $alert['code'] ?? null;
        if (! $code) {
            return false;
        }
        $since = now()->subDay()->toIso8601String();

        return $this->alerts->all()->contains(function (array $row) use ($code, $since) {
            if (($row['code'] ?? null) !== $code) {
                return false;
            }

            return ($row['createdAt'] ?? '') >= $since;
        });
    }
}
