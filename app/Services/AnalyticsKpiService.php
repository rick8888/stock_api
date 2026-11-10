<?php

namespace App\Services;

use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\StockMovementRepository;
use App\Support\DemoDate;
use App\Support\Tenant;

class AnalyticsKpiService
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly PurchaseOrderRepository $orders,
        private readonly StockItemRepository $stock,
        private readonly ProductRepository $products,
        private readonly StockMovementRepository $movements,
        private readonly RouteRepository $routes,
        private readonly Tenant $tenant,
    ) {}

    public function snapshot(): array
    {
        $deliveries = $this->tenant->filterByWarehouseKeys($this->deliveries->all(), ['warehouseId']);
        $orders = $this->tenant->filterByWarehouseKeys($this->orders->all(), ['warehouseId']);
        $stock = $this->tenant->filterByWarehouseKeys($this->stock->all(), ['warehouseId']);
        $products = $this->tenant->filterProducts($this->products->all());
        $routes = $this->tenant->filterByWarehouseKeys($this->routes->all(), ['warehouseId']);
        $movements = $this->tenant->filterByWarehouseKeys($this->movements->all());

        $today = now()->toDateString();
        $windowStart = now()->subDays(30)->toDateString();

        $delivered = $deliveries->filter(function (array $row) use ($windowStart) {
            if (($row['status'] ?? '') !== 'delivered') {
                return false;
            }
            $day = substr((string) DemoDate::rebase($row['signedAt'] ?? $row['scheduledAt'] ?? ''), 0, 10);

            return $day >= $windowStart;
        });
        $otifOk = $delivered->filter(function (array $row) {
            $scheduled = substr((string) DemoDate::rebase($row['scheduledAt'] ?? ''), 0, 10);
            $signed = substr((string) DemoDate::rebase($row['signedAt'] ?? ''), 0, 10);
            if (! $scheduled || ! $signed) {
                return false;
            }

            return $signed <= $scheduled;
        })->count();
        $otifTotal = max(1, $delivered->count());
        $otifRate = round(($otifOk / $otifTotal) * 100, 1);

        $stockouts = 0;
        $watch = 0;
        $productsById = $products->keyBy('id');
        foreach ($stock as $row) {
            $product = $productsById->get($row['productId'] ?? '');
            if (! $product || ! empty($product['archived'])) {
                continue;
            }
            $available = max(0, (int) ($row['quantity'] ?? 0) - (int) ($row['reserved'] ?? 0));
            $min = (int) ($product['minStock'] ?? 0);
            if ($available <= 0) {
                $stockouts++;
            } elseif ($min > 0 && $available < $min) {
                $watch++;
            }
        }

        $delayedOrders = $orders->filter(function (array $row) use ($today) {
            if (in_array($row['status'] ?? '', ['received', 'cancelled', 'canceled'], true)) {
                return false;
            }
            $expected = substr((string) DemoDate::rebase($row['expectedDate'] ?? ''), 0, 10);

            return $expected !== '' && $expected < $today;
        })->count();

        $failed = $deliveries->filter(fn (array $row) => ($row['status'] ?? '') === 'failed')->count();
        $deliveryCost = round($routes->sum(fn (array $row) => (float) ($row['distanceKm'] ?? 0) * 2.4), 0);

        $issued = $movements->filter(fn (array $row) => ($row['type'] ?? '') === 'issue')->sum('quantity');
        $received = $movements->filter(fn (array $row) => ($row['type'] ?? '') === 'receive')->sum('quantity');

        return [
            'otifRate' => $otifRate,
            'otifDelivered' => $delivered->count(),
            'otifOnTime' => $otifOk,
            'stockouts' => $stockouts,
            'stockWatch' => $watch,
            'delayedOrders' => $delayedOrders,
            'failedDeliveries' => $failed,
            'deliveryCostMad' => $deliveryCost,
            'costPerDelivery' => $delivered->count()
                ? round($deliveryCost / max(1, $delivered->count()), 0)
                : 0,
            'issued' => (int) $issued,
            'received' => (int) $received,
            'netFlow' => (int) $received - (int) $issued,
            'windowDays' => 30,
        ];
    }
}
