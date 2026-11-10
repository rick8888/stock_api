<?php

namespace App\Services;

use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\InventorySessionRepository;
use App\Repositories\Eloquent\PlanningEventRepository;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Tenant;
use Illuminate\Support\Collection;

class PlanningService
{
    private const DEMO_FROM = '2026-08-20';

    private const DEMO_TO = '2026-08-31';

    private const DEMO_TODAY = '2026-08-26';

    public function __construct(
        private readonly PlanningEventRepository $planning,
        private readonly RouteRepository $routes,
        private readonly DeliveryRepository $deliveries,
        private readonly ReceiptRepository $receipts,
        private readonly InventorySessionRepository $inventories,
        private readonly TaskItemRepository $tasks,
        private readonly WarehouseRepository $warehouses,
        private readonly Tenant $tenant,
    ) {}

    public function list(?string $from = null, ?string $to = null): Collection
    {
        $events = collect()
            ->concat($this->stored())
            ->concat($this->fromRoutes())
            ->concat($this->fromDeliveries())
            ->concat($this->fromReceipts())
            ->concat($this->fromInventories())
            ->concat($this->fromTasks())
            ->unique(fn (array $event) => ($event['type'] ?? '').'|'.($event['relatedId'] ?? $event['id']))
            ->sortBy('date')
            ->values();

        if ($from) {
            $events = $events->filter(fn (array $event) => substr((string) $event['date'], 0, 10) >= $from);
        }
        if ($to) {
            $events = $events->filter(fn (array $event) => substr((string) $event['date'], 0, 10) <= $to);
        }

        return $events->values();
    }

    private function stored(): Collection
    {
        $rows = $this->tenant->bypassTenantFilter()
            ? $this->planning->all()
            : $this->planning->forCompany($this->tenant->companyId());

        return $rows->map(function (array $event) {
            $event['date'] = $this->rebaseDemoDate((string) ($event['date'] ?? ''));

            return $event;
        });
    }

    private function fromRoutes(): Collection
    {
        return $this->routes->all()->map(function (array $route) {
            $date = (string) ($route['date'] ?? '');

            return [
                'id' => 'pl-route-'.($route['id'] ?? ''),
                'title' => $route['name'] ?? 'Tournée',
                'date' => $this->withTime($date, '08:00:00'),
                'type' => 'delivery',
                'relatedId' => $route['id'] ?? null,
            ];
        });
    }

    private function fromDeliveries(): Collection
    {
        return $this->deliveries->all()->map(function (array $delivery) {
            return [
                'id' => 'pl-dlv-'.($delivery['id'] ?? ''),
                'title' => $delivery['client'] ?? 'Livraison',
                'date' => $delivery['scheduledAt'] ?? $delivery['eta'] ?? now()->toIso8601String(),
                'type' => 'delivery',
                'relatedId' => $delivery['id'] ?? null,
            ];
        });
    }

    private function fromReceipts(): Collection
    {
        return $this->receipts->all()
            ->filter(fn (array $receipt) => ($receipt['status'] ?? '') !== 'validated')
            ->map(function (array $receipt) {
                return [
                    'id' => 'pl-rcp-'.($receipt['id'] ?? ''),
                    'title' => $receipt['code'] ?? 'Réception',
                    'date' => $receipt['date'] ?? now()->toIso8601String(),
                    'type' => 'receipt',
                    'relatedId' => $receipt['id'] ?? null,
                ];
            });
    }

    private function fromInventories(): Collection
    {
        $names = $this->warehouses->all()->keyBy('id');

        return $this->inventories->all()
            ->filter(fn (array $session) => ($session['status'] ?? '') !== 'completed')
            ->map(function (array $session) use ($names) {
                $row = $names->get($session['warehouseId'] ?? '');
                $warehouse = is_array($row) ? ($row['name'] ?? 'entrepôt') : 'entrepôt';

                return [
                    'id' => 'pl-inv-'.($session['id'] ?? ''),
                    'title' => 'Inventaire '.$warehouse,
                    'date' => $session['startedAt'] ?? now()->toIso8601String(),
                    'type' => 'inventory',
                    'relatedId' => $session['id'] ?? null,
                ];
            });
    }

    private function fromTasks(): Collection
    {
        return $this->tasks->all()
            ->filter(fn (array $task) => ($task['status'] ?? '') !== 'done')
            ->map(function (array $task) {
                return [
                    'id' => 'pl-tsk-'.($task['id'] ?? ''),
                    'title' => $task['title'] ?? 'Tâche',
                    'date' => $task['dueAt'] ?? now()->toIso8601String(),
                    'type' => 'task',
                    'relatedId' => $task['id'] ?? null,
                ];
            });
    }

    private function rebaseDemoDate(string $iso): string
    {
        $key = substr($iso, 0, 10);
        if ($key < self::DEMO_FROM || $key > self::DEMO_TO) {
            return $iso;
        }

        try {
            $event = new \DateTimeImmutable($iso);
            $demo = new \DateTimeImmutable(self::DEMO_TODAY);
            $today = new \DateTimeImmutable('today');
            $days = (int) $demo->diff($today)->format('%r%a');
            $shifted = $event->modify($days.' days');
        } catch (\Exception) {
            return $iso;
        }

        return strlen($iso) > 10
            ? $shifted->format('Y-m-d\TH:i:s')
            : $shifted->format('Y-m-d');
    }

    private function withTime(string $date, string $time): string
    {
        if (strlen($date) > 10) {
            return $date;
        }
        if ($date === '') {
            return now()->format('Y-m-d').'T'.$time;
        }

        return $date.'T'.$time;
    }
}
