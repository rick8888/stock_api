<?php

namespace App\Services;

use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\InventorySessionRepository;
use App\Repositories\Eloquent\PlanningEventRepository;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\DemoDate;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PlanningService
{
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
        $events = $this->visibleEvents();

        if ($from) {
            $events = $events->filter(fn (array $event) => substr((string) $event['date'], 0, 10) >= $from);
        }
        if ($to) {
            $events = $events->filter(fn (array $event) => substr((string) $event['date'], 0, 10) <= $to);
        }

        return $events->values();
    }

    /**
     * Board ops pour /planning : compteurs + semaine + journée calculés serveur.
     *
     * @return array{
     *   today: string,
     *   selectedDay: string,
     *   weekOffset: int,
     *   type: string,
     *   counts: array{today: int, week: int, next: int},
     *   weekDays: list<array{key: string, count: int, isToday: bool}>,
     *   types: list<array{id: string, count: int}>,
     *   dayEvents: list<array<string, mixed>>,
     *   upcoming: list<array<string, mixed>>,
     *   events: list<array<string, mixed>>
     * }
     */
    public function board(int $weekOffset = 0, ?string $day = null, ?string $type = null): array
    {
        $today = now()->toDateString();
        $type = $type && $type !== '' ? $type : 'all';
        $events = $this->visibleEvents()->all();

        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeeks($weekOffset)->startOfDay();
        $weekKeys = [];
        for ($i = 0; $i < 7; $i++) {
            $weekKeys[] = $monday->copy()->addDays($i)->toDateString();
        }

        $selectedDay = $day && in_array($day, $weekKeys, true)
            ? $day
            : (in_array($today, $weekKeys, true) ? $today : ($weekKeys[0] ?? $today));

        $byDay = [];
        foreach ($weekKeys as $key) {
            $byDay[$key] = 0;
        }
        $todayCount = 0;
        $nextCount = 0;
        $typeCounts = [];

        foreach ($events as $event) {
            $key = substr((string) ($event['date'] ?? ''), 0, 10);
            $eventType = (string) ($event['type'] ?? 'task');
            $typeCounts[$eventType] = ($typeCounts[$eventType] ?? 0) + 1;
            if ($key === $today) {
                $todayCount++;
            }
            if ($key > $today) {
                $nextCount++;
            }
            if (isset($byDay[$key])) {
                $byDay[$key]++;
            }
        }

        $weekDays = [];
        foreach ($weekKeys as $key) {
            $weekDays[] = [
                'key' => $key,
                'count' => $byDay[$key] ?? 0,
                'isToday' => $key === $today,
            ];
        }

        $weekCount = array_sum($byDay);

        $types = [['id' => 'all', 'count' => count($events)]];
        foreach (['delivery', 'receipt', 'inventory', 'meeting', 'task'] as $id) {
            if (($typeCounts[$id] ?? 0) > 0) {
                $types[] = ['id' => $id, 'count' => $typeCounts[$id]];
            }
        }

        $filtered = array_values(array_filter(
            $events,
            fn (array $event) => $type === 'all' || ($event['type'] ?? '') === $type,
        ));

        $dayEvents = array_values(array_filter(
            $filtered,
            fn (array $event) => substr((string) ($event['date'] ?? ''), 0, 10) === $selectedDay,
        ));

        $upcoming = array_values(array_filter(
            $filtered,
            fn (array $event) => substr((string) ($event['date'] ?? ''), 0, 10) >= $today,
        ));
        $upcoming = array_slice($upcoming, 0, 6);

        return [
            'today' => $today,
            'selectedDay' => $selectedDay,
            'weekOffset' => $weekOffset,
            'type' => $type,
            'counts' => [
                'today' => $todayCount,
                'week' => $weekCount,
                'next' => $nextCount,
            ],
            'weekDays' => $weekDays,
            'types' => $types,
            'dayEvents' => $dayEvents,
            'upcoming' => $upcoming,
            'events' => $events,
        ];
    }

    private function visibleEvents(): Collection
    {
        return collect()
            ->concat($this->stored())
            ->concat($this->fromRoutes())
            ->concat($this->fromDeliveries())
            ->concat($this->fromReceipts())
            ->concat($this->fromInventories())
            ->concat($this->fromTasks())
            ->unique(fn (array $event) => ($event['type'] ?? '').'|'.($event['relatedId'] ?? $event['id']))
            ->filter(fn (array $event) => $this->visibleToRole($event))
            ->sortBy('date')
            ->values();
    }

    /** @param  array<string, mixed>  $event */
    private function visibleToRole(array $event): bool
    {
        $role = $this->tenant->role();
        $type = $event['type'] ?? '';

        return match ($role) {
            'DRIVER' => $type === 'delivery',
            'FINANCE' => in_array($type, ['meeting', 'receipt'], true),
            'DISPATCHER' => in_array($type, ['delivery', 'task'], true),
            'PROCUREMENT' => $type !== 'inventory',
            default => true,
        };
    }

    private function stored(): Collection
    {
        $rows = $this->tenant->bypassTenantFilter()
            ? $this->planning->all()
            : $this->planning->forCompany($this->tenant->companyId());

        return $rows->map(function (array $event) {
            $event['date'] = DemoDate::rebase((string) ($event['date'] ?? '')) ?? ($event['date'] ?? '');

            return $event;
        });
    }

    private function fromRoutes(): Collection
    {
        return $this->routes->all()->map(function (array $route) {
            $date = DemoDate::rebase((string) ($route['date'] ?? '')) ?? (string) ($route['date'] ?? '');

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
            $raw = (string) ($delivery['scheduledAt'] ?? $delivery['eta'] ?? now()->toIso8601String());

            return [
                'id' => 'pl-dlv-'.($delivery['id'] ?? ''),
                'title' => $delivery['client'] ?? 'Livraison',
                'date' => DemoDate::rebase($raw) ?? $raw,
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
                $raw = (string) ($receipt['date'] ?? now()->toIso8601String());

                return [
                    'id' => 'pl-rcp-'.($receipt['id'] ?? ''),
                    'title' => $receipt['code'] ?? 'Réception',
                    'date' => DemoDate::rebase($raw) ?? $raw,
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
                $raw = (string) ($session['startedAt'] ?? now()->toIso8601String());

                return [
                    'id' => 'pl-inv-'.($session['id'] ?? ''),
                    'title' => 'Inventaire '.$warehouse,
                    'date' => DemoDate::rebase($raw) ?? $raw,
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
                $raw = (string) ($task['dueAt'] ?? now()->toIso8601String());

                return [
                    'id' => 'pl-tsk-'.($task['id'] ?? ''),
                    'title' => $task['title'] ?? 'Tâche',
                    'date' => DemoDate::rebase($raw) ?? $raw,
                    'type' => 'task',
                    'relatedId' => $task['id'] ?? null,
                ];
            });
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
