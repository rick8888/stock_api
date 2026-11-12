<?php

namespace App\Services;

use App\Repositories\Eloquent\AlertRepository;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\InventorySessionRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Repositories\Eloquent\ShippingNoteRepository;
use App\Support\AlertVisibility;
use App\Support\DemoDate;
use App\Support\RolePermissions;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Brief ops /today — toute la logique métier côté API (le front n’affiche que le JSON).
 */
class TodayBriefService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly TaskService $taskService,
        private readonly StockOverviewService $stockOverview,
        private readonly ReceiptRepository $receipts,
        private readonly InventorySessionRepository $inventories,
        private readonly PurchaseOrderRepository $orders,
        private readonly ShippingNoteRepository $notes,
        private readonly DeliveryRepository $deliveries,
        private readonly PlanningService $planning,
        private readonly Tenant $tenant,
    ) {}

    public function brief(): array
    {
        $role = $this->tenant->role();
        $userId = $this->tenant->actorId();
        $userWarehouses = array_values(array_filter($this->tenant->user()?->warehouse_ids ?? []));
        $companyWarehouses = $this->tenant->companyWarehouseIds();
        $scope = $userWarehouses ?: $companyWarehouses;

        $alerts = $this->visibleAlerts($role, $userWarehouses, $userId);
        $unread = $alerts->filter(fn (array $a) => empty($a['read']));
        $unreadAlerts = $unread->values();

        $queue = [];
        foreach ($unreadAlerts as $alert) {
            $queue[] = [
                'id' => 'alert-'.$alert['id'],
                'title' => $alert['title'] ?? 'Alerte',
                'detail' => $alert['message'] ?? '',
                'tone' => $alert['severity'] ?? 'info',
                'primaryLabel' => AlertVisibility::actionLabel($alert),
                'primaryTo' => AlertVisibility::href($alert, $role),
                'source' => 'alert',
            ];
        }

        $relatedIds = $unreadAlerts
            ->pluck('relatedId')
            ->filter()
            ->values()
            ->all();
        $hasStockAlerts = $unreadAlerts->contains(fn (array $a) => ($a['domain'] ?? '') === 'stock');
        $hasPoDelayAlerts = $unreadAlerts->contains(
            fn (array $a) => ($a['domain'] ?? '') === 'procurement'
                && preg_match('/retard|delay|oa-|commande/i', ($a['title'] ?? '').' '.($a['message'] ?? '')),
        );
        $hasLogisticsAlerts = $unreadAlerts->contains(fn (array $a) => ($a['domain'] ?? '') === 'logistics');

        foreach ($this->liveActions($role, $scope, $relatedIds, $hasStockAlerts, $hasPoDelayAlerts, $hasLogisticsAlerts) as $action) {
            $queue[] = $action;
        }

        $queue = $this->uniqueById($queue);
        usort($queue, function (array $a, array $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];

            return ($rank[$a['tone'] ?? 'info'] <=> $rank[$b['tone'] ?? 'info'])
                ?: strcmp($a['id'], $b['id']);
        });

        $actionableCount = count($queue);
        $criticalInQueue = count(array_filter($queue, fn (array $i) => ($i['tone'] ?? '') === 'critical'));
        $displayed = array_slice($queue, 0, 14);

        $myTasks = $this->taskService->list()
            ->filter(fn (array $t) => ($t['status'] ?? '') !== 'done')
            ->sortBy('dueAt')
            ->values();

        $risks = array_values(array_filter(
            $this->stockOverview->risks(),
            fn (array $r) => ! $scope || in_array($r['warehouseId'] ?? '', $scope, true),
        ));
        $criticalStock = array_values(array_filter($risks, fn (array $r) => ($r['days'] ?? 99) <= 3));
        $watchStock = array_slice($risks, 0, 4);

        $today = now()->toDateString();
        $agenda = [];
        if (RolePermissions::allows($role, 'task.view')) {
            $agenda = $this->planning->list(null, null)
                ->filter(fn (array $e) => str_starts_with((string) ($e['date'] ?? ''), $today))
                ->sortBy('date')
                ->values()
                ->all();
        }

        return [
            'queue' => $displayed,
            'toHandle' => $displayed,
            'toWatch' => [],
            'recommendations' => [],
            'tasks' => $myTasks->take(5)->values()->all(),
            'activity' => [],
            'watchStock' => $watchStock,
            'agenda' => $agenda,
            'prompts' => [],
            'unreadAlerts' => $unread->count(),
            'criticalAlerts' => $criticalInQueue,
            'openTasksCount' => $myTasks->count(),
            'criticalStockCount' => count($criticalStock),
            'actionableCount' => $actionableCount,
            'signals' => [
                [
                    'id' => 'alerts',
                    'label' => 'Alertes non lues',
                    'value' => $unread->count(),
                    'trend' => $criticalInQueue ? $criticalInQueue.' critiques' : ($unread->count() ? 'à traiter' : 'à jour'),
                    'trendUp' => $unread->count() === 0,
                    'tone' => $criticalInQueue ? 'critical' : ($unread->count() ? 'warning' : 'success'),
                ],
                [
                    'id' => 'critical',
                    'label' => 'Critiques',
                    'value' => $criticalInQueue,
                    'trend' => 'dans la file ops',
                    'trendUp' => $criticalInQueue === 0,
                    'tone' => $criticalInQueue ? 'critical' : 'success',
                ],
                [
                    'id' => 'tasks',
                    'label' => 'Tâches ouvertes',
                    'value' => $myTasks->count(),
                    'trend' => 'assignées à vous',
                    'trendUp' => true,
                    'tone' => 'info',
                ],
                [
                    'id' => 'stock',
                    'label' => 'Stock ≤ 3 j',
                    'value' => count($criticalStock),
                    'trend' => count($criticalStock) ? 'couverture' : 'ok',
                    'trendUp' => count($criticalStock) === 0,
                    'tone' => count($criticalStock) ? 'critical' : 'success',
                ],
            ],
        ];
    }

    private function visibleAlerts(string $role, array $userWarehouses, string $userId): Collection
    {
        $base = $this->tenant->bypassTenantFilter()
            ? $this->alerts->all()
            : $this->alerts->forWarehouses($this->tenant->companyWarehouseIds());

        return $base
            ->filter(fn (array $alert) => AlertVisibility::visibleTo($alert, $role, $userWarehouses, $userId))
            ->values();
    }

    /**
     * Actions live (BR, inventaire, DA, BL…) — uniquement si absentes des alertes.
     *
     * @param  list<string>  $relatedIds
     * @param  list<string>  $scope
     * @return list<array<string, mixed>>
     */
    private function liveActions(
        string $role,
        array $scope,
        array $relatedIds,
        bool $hasStockAlerts,
        bool $hasPoDelayAlerts,
        bool $hasLogisticsAlerts,
    ): array {
        $out = [];
        $related = array_flip($relatedIds);

        if (in_array($role, ['WAREHOUSE_MANAGER', 'ADMIN', 'MANAGER'], true)
            && RolePermissions::allows($role, 'stock.view')) {
            foreach ($this->receipts->all() as $receipt) {
                if (($receipt['status'] ?? '') === 'validated') {
                    continue;
                }
                if ($scope && ! in_array($receipt['warehouseId'] ?? '', $scope, true)) {
                    continue;
                }
                if (isset($related[$receipt['id'] ?? ''])) {
                    continue;
                }
                $out[] = [
                    'id' => 'rcp-'.$receipt['id'],
                    'title' => 'Réceptionner '.($receipt['code'] ?? strtoupper((string) $receipt['id'])),
                    'detail' => 'Contrôler l’arrivage, puis mettre le stock à jour.',
                    'tone' => ($receipt['status'] ?? '') === 'partial' ? 'warning' : 'info',
                    'primaryLabel' => 'Ouvrir',
                    'primaryTo' => '/procurement?tab=receipts&receipt='.$receipt['id'],
                    'source' => 'receipt',
                ];
                if (count(array_filter($out, fn ($i) => str_starts_with($i['id'], 'rcp-'))) >= 3) {
                    break;
                }
            }
        }

        if (RolePermissions::allows($role, 'stock.view')) {
            $invCount = 0;
            foreach ($this->inventories->all() as $session) {
                $status = $session['status'] ?? '';
                if ($status === 'completed') {
                    continue;
                }
                if ($scope && ! in_array($session['warehouseId'] ?? '', $scope, true)) {
                    continue;
                }
                if ($role === 'WAREHOUSE_MANAGER' && ! in_array($status, ['in_progress', 'pending_review'], true)) {
                    continue;
                }
                if (! in_array($role, ['WAREHOUSE_MANAGER', 'MANAGER', 'ADMIN'], true)) {
                    continue;
                }
                $gaps = collect($session['lines'] ?? [])->filter(
                    fn (array $line) => ($line['counted'] ?? null) !== ($line['expected'] ?? null),
                )->count();
                $out[] = [
                    'id' => 'inv-'.$session['id'],
                    'title' => $status === 'pending_review' ? 'Valider les écarts d’inventaire' : 'Continuer l’inventaire',
                    'detail' => $status === 'pending_review'
                        ? $gaps.' écart(s) en attente de validation.'
                        : 'Comptage en cours — enregistrez puis soumettez les écarts.',
                    'tone' => $status === 'pending_review' ? 'warning' : 'info',
                    'primaryLabel' => 'Ouvrir',
                    'primaryTo' => '/stock?tab=inventory&session='.$session['id'],
                    'source' => 'inventory',
                ];
                if (++$invCount >= 2) {
                    break;
                }
            }
        }

        if (in_array($role, ['PROCUREMENT', 'MANAGER', 'ADMIN'], true)
            && RolePermissions::allows($role, 'procurement.view')) {
            // Demandes approuvées : portées surtout par alertes OpsNotifier ; pas de second calcul ici.
        }

        if (! $hasPoDelayAlerts
            && in_array($role, ['PROCUREMENT', 'MANAGER', 'ADMIN', 'FINANCE'], true)
            && RolePermissions::allows($role, 'purchase.view')) {
            $today = now()->toDateString();
            $n = 0;
            foreach ($this->orders->all() as $order) {
                if (in_array($order['status'] ?? '', ['received', 'cancelled', 'canceled'], true)) {
                    continue;
                }
                $expected = substr((string) DemoDate::rebase($order['expectedDate'] ?? ''), 0, 10);
                if ($expected === '' || $expected >= $today) {
                    continue;
                }
                if (isset($related[$order['id'] ?? ''])) {
                    continue;
                }
                $out[] = [
                    'id' => 'po-'.$order['id'],
                    'title' => 'Retard '.($order['code'] ?? strtoupper((string) $order['id'])),
                    'detail' => 'Attendue le '.$expected.', toujours au statut '.($order['status'] ?? '').'.',
                    'tone' => 'warning',
                    'primaryLabel' => 'Ouvrir',
                    'primaryTo' => '/procurement?tab=orders&order='.$order['id'],
                    'source' => 'purchase',
                ];
                if (++$n >= 2) {
                    break;
                }
            }
        }

        if (in_array($role, ['DISPATCHER', 'WAREHOUSE_MANAGER', 'MANAGER', 'ADMIN'], true)
            && RolePermissions::allows($role, 'delivery.view')) {
            $n = 0;
            foreach ($this->notes->all() as $note) {
                if (($note['status'] ?? '') !== 'ready') {
                    continue;
                }
                if ($scope && ! in_array($note['warehouseId'] ?? '', $scope, true)) {
                    continue;
                }
                if (isset($related[$note['id'] ?? ''])) {
                    continue;
                }
                $out[] = [
                    'id' => 'bl-'.$note['id'],
                    'title' => 'Préparer / charger '.($note['code'] ?? $note['id']),
                    'detail' => ($note['client'] ?? '').' — '.($note['city'] ?? ''),
                    'tone' => 'info',
                    'primaryLabel' => 'Ouvrir',
                    'primaryTo' => '/logistics?tab=notes&note='.$note['id'],
                    'source' => 'shipping_note',
                ];
                if (++$n >= 2) {
                    break;
                }
            }
        }

        if (! $hasLogisticsAlerts
            && in_array($role, ['DISPATCHER', 'MANAGER', 'ADMIN', 'DRIVER'], true)
            && RolePermissions::allows($role, 'delivery.view')) {
            $n = 0;
            foreach ($this->deliveries->all() as $delivery) {
                if (($delivery['status'] ?? '') !== 'at_risk') {
                    continue;
                }
                if (isset($related[$delivery['id'] ?? ''])) {
                    continue;
                }
                $out[] = [
                    'id' => 'dlv-'.$delivery['id'],
                    'title' => 'Livraison à risque',
                    'detail' => ($delivery['client'] ?? '').' — créneau sous tension.',
                    'tone' => 'warning',
                    'primaryLabel' => 'Ouvrir',
                    'primaryTo' => $role === 'DRIVER'
                        ? '/driver/today'
                        : '/logistics?tab=deliveries&delivery='.$delivery['id'],
                    'source' => 'delivery',
                ];
                if (++$n >= 2) {
                    break;
                }
            }
        }

        // Stock : jamais de « rupture live » dans la file — uniquement via alertes + watchStock.
        unset($hasStockAlerts);

        return $out;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function uniqueById(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if (! $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $item;
        }

        return $out;
    }
}
