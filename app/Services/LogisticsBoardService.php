<?php

namespace App\Services;

use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Carriers;
use App\Support\Geo;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Board /logistics — overviews + compteurs + pagination serveur.
 */
class LogisticsBoardService
{
    private const DELIVERY_LABEL = [
        'prepared' => 'Préparée',
        'in_transit' => 'En tournée',
        'delivered' => 'Livrée',
        'failed' => 'Échec',
        'at_risk' => 'À risque',
    ];

    private const SHIPMENT_LABEL = [
        'preparing' => 'Préparation',
        'in_transit' => 'En transit',
        'done' => 'Terminée',
        'delayed' => 'Retard',
    ];

    private const ROUTE_LABEL = [
        'planned' => 'Planifiée',
        'in_progress' => 'En cours',
        'done' => 'Terminée',
    ];

    private const VEHICLE_LABEL = [
        'available' => 'Disponible',
        'on_mission' => 'En mission',
        'maintenance' => 'Maintenance',
    ];

    private const DRIVER_LABEL = [
        'available' => 'Disponible',
        'on_route' => 'En tournée',
        'off' => 'Repos',
    ];

    private const NOTE_LABEL = [
        'ready' => 'Disponible',
        'picking' => 'En préparation',
        'loaded' => 'Chargé',
        'assigned' => 'Affecté',
        'delivered' => 'Livré',
    ];

    public function __construct(
        private readonly LogisticsService $logistics,
        private readonly DeliveryService $deliveries,
        private readonly WarehouseRepository $warehouses,
        private readonly ProductRepository $products,
        private readonly CustomerRepository $customers,
        private readonly UserDirectory $users,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(string $section = 'deliveries', ?string $filter = null, int $page = 1, int $pageSize = 8, ?string $focusId = null): array
    {
        $pageSize = max(1, min(50, $pageSize));
        $section = in_array($section, ['notes', 'deliveries', 'routes', 'shipments', 'fleet'], true)
            ? $section
            : 'deliveries';

        $payload = match ($section) {
            'notes' => $this->notesBoard($filter ?: 'ready', $page, $pageSize, $focusId),
            'routes' => $this->routesBoard($filter ?: 'all', $page, $pageSize, $focusId),
            'shipments' => $this->shipmentsBoard($filter ?: 'all', $page, $pageSize, $focusId),
            'fleet' => $this->fleetBoard($filter ?: 'all', $page, $pageSize, $focusId),
            default => $this->deliveriesBoard($filter ?: 'all', $page, $pageSize, $focusId),
        };
        $payload['metrics'] = $this->metrics();
        $payload['formOptions'] = $this->formOptions();

        return $payload;
    }

    /** @return array<string, int> */
    private function metrics(): array
    {
        $notes = $this->scopedNotes();
        $deliveries = $this->scopedDeliveries();
        $routes = $this->scopedRoutes();

        return [
            'readyNotes' => $notes->filter(fn (array $n) => in_array($n['status'] ?? '', ['ready', 'picking', 'loaded'], true))->count(),
            'risk' => $deliveries->filter(fn (array $d) => in_array($d['status'] ?? '', ['at_risk', 'failed'], true))->count(),
            'moving' => $deliveries->filter(fn (array $d) => ($d['status'] ?? '') === 'in_transit')->count(),
            'delivered' => $deliveries->filter(fn (array $d) => ($d['status'] ?? '') === 'delivered')->count(),
            'routes' => $routes->filter(fn (array $r) => ($r['status'] ?? '') === 'in_progress')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function notesBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->noteOverviews();
        $counts = [
            'ready' => 0,
            'picking' => 0,
            'loaded' => 0,
            'assigned' => 0,
            'delivered' => 0,
            'all' => count($rows),
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['note']['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            if ($filter === 'all') {
                return true;
            }

            return ($row['note']['status'] ?? '') === $filter;
        }));
        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['note']['id'] ?? ''));

        return [
            'section' => 'notes',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /** @return array<string, mixed> */
    private function deliveriesBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->deliveryOverviews();
        $counts = [
            'all' => count($rows),
            'at_risk' => 0,
            'in_transit' => 0,
            'prepared' => 0,
            'delivered' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['delivery']['status'] ?? '');
            if (in_array($status, ['at_risk', 'failed'], true)) {
                $counts['at_risk']++;
            }
            if ($status === 'in_transit') {
                $counts['in_transit']++;
            }
            if ($status === 'prepared') {
                $counts['prepared']++;
            }
            if ($status === 'delivered') {
                $counts['delivered']++;
            }
        }
        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            $status = (string) ($row['delivery']['status'] ?? '');
            if ($filter === 'all') {
                return true;
            }
            if ($filter === 'at_risk') {
                return in_array($status, ['at_risk', 'failed'], true);
            }

            return $status === $filter;
        }));
        usort($filtered, fn (array $a, array $b) => strcmp((string) ($a['delivery']['scheduledAt'] ?? ''), (string) ($b['delivery']['scheduledAt'] ?? '')));
        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['delivery']['id'] ?? ''));

        return [
            'section' => 'deliveries',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /** @return array<string, mixed> */
    private function routesBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->routeOverviews();
        $counts = [
            'all' => count($rows),
            'planned' => 0,
            'in_progress' => 0,
            'done' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['route']['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            if ($filter === 'all') {
                return true;
            }

            return ($row['route']['status'] ?? '') === $filter;
        }));
        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['route']['id'] ?? ''));

        return [
            'section' => 'routes',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /** @return array<string, mixed> */
    private function shipmentsBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->shipmentOverviews();
        $counts = [
            'all' => count($rows),
            'in_transit' => 0,
            'delayed' => 0,
            'preparing' => 0,
            'done' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['shipment']['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            if ($filter === 'all') {
                return true;
            }

            return ($row['shipment']['status'] ?? '') === $filter;
        }));
        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['shipment']['id'] ?? ''));

        return [
            'section' => 'shipments',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /** @return array<string, mixed> */
    private function fleetBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $vehicles = $this->vehicleRows();
        $drivers = $this->driverRows();
        $counts = [
            'vehicles' => count($vehicles),
            'drivers' => count($drivers),
            'on_mission' => count(array_filter($vehicles, fn (array $v) => ($v['vehicle']['status'] ?? '') === 'on_mission')),
            'on_route' => count(array_filter($drivers, fn (array $d) => ($d['driver']['status'] ?? '') === 'on_route')),
            'all' => count($vehicles) + count($drivers),
        ];

        if ($filter === 'drivers') {
            $pageData = $this->paginate($drivers, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['driver']['id'] ?? ''));

            return [
                'section' => 'fleet',
                'filter' => $filter,
                'counts' => $counts,
                'vehicles' => $vehicles,
                'drivers' => $pageData['items'],
                ...$pageData,
            ];
        }

        $pageData = $this->paginate($vehicles, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['vehicle']['id'] ?? ''));

        return [
            'section' => 'fleet',
            'filter' => $filter === 'vehicles' ? 'vehicles' : 'all',
            'counts' => $counts,
            'vehicles' => $pageData['items'],
            'drivers' => $drivers,
            ...$pageData,
        ];
    }

    /**
     * @return array{
     *   products: list<array<string, mixed>>,
     *   warehouses: list<array<string, mixed>>,
     *   customers: list<array<string, mixed>>,
     *   vehicles: list<array<string, mixed>>,
     *   drivers: list<array<string, mixed>>,
     *   carriers: list<array<string, mixed>>,
     *   routes: list<array<string, mixed>>
     * }
     */
    private function formOptions(): array
    {
        $scope = $this->userWarehouseScope();
        $products = [];
        foreach ($this->products->all() as $product) {
            if (! empty($product['archived'])) {
                continue;
            }
            $products[] = [
                'id' => (string) ($product['id'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'unit' => (string) ($product['unit'] ?? 'unité'),
                'barcode' => $product['barcode'] ?? null,
                'trackLots' => (bool) ($product['trackLots'] ?? false),
                'archived' => false,
            ];
        }
        $warehouses = [];
        foreach ($this->warehouses->all() as $warehouse) {
            $id = (string) ($warehouse['id'] ?? '');
            if ($scope && ! in_array($id, $scope, true)) {
                continue;
            }
            if (isset($warehouse['active']) && $warehouse['active'] === false) {
                continue;
            }
            $warehouses[] = [
                'id' => $id,
                'name' => (string) ($warehouse['name'] ?? $id),
                'city' => (string) ($warehouse['city'] ?? ''),
                'address' => (string) ($warehouse['address'] ?? ''),
                'lat' => $warehouse['lat'] ?? null,
                'lng' => $warehouse['lng'] ?? null,
            ];
        }
        $customers = [];
        $customerRows = $this->tenant->bypassTenantFilter()
            ? $this->customers->all()
            : $this->customers->forCompany($this->tenant->companyId());
        foreach ($customerRows as $customer) {
            if (isset($customer['active']) && $customer['active'] === false) {
                continue;
            }
            $customers[] = [
                'id' => (string) ($customer['id'] ?? ''),
                'name' => (string) ($customer['name'] ?? ''),
                'phone' => (string) ($customer['phone'] ?? ''),
                'email' => (string) ($customer['email'] ?? ''),
                'addresses' => $customer['addresses'] ?? [],
            ];
        }
        $vehicles = [];
        foreach ($this->scopedVehicles() as $vehicle) {
            $vehicles[] = [
                'id' => (string) ($vehicle['id'] ?? ''),
                'name' => (string) ($vehicle['name'] ?? ''),
                'plate' => (string) ($vehicle['plate'] ?? ''),
                'warehouseId' => (string) ($vehicle['warehouseId'] ?? ''),
                'status' => (string) ($vehicle['status'] ?? 'available'),
            ];
        }
        $drivers = [];
        foreach ($this->scopedDrivers() as $driver) {
            $drivers[] = [
                'id' => (string) ($driver['id'] ?? ''),
                'userId' => (string) ($driver['userId'] ?? ''),
                'phone' => (string) ($driver['phone'] ?? ''),
                'vehicleId' => $driver['vehicleId'] ?? null,
                'status' => (string) ($driver['status'] ?? 'available'),
                'name' => $this->personName((string) ($driver['id'] ?? '')),
            ];
        }
        $routes = [];
        foreach ($this->scopedRoutes() as $route) {
            $routes[] = [
                'id' => (string) ($route['id'] ?? ''),
                'name' => (string) ($route['name'] ?? ''),
                'warehouseId' => (string) ($route['warehouseId'] ?? ''),
                'driverId' => (string) ($route['driverId'] ?? ''),
                'vehicleId' => (string) ($route['vehicleId'] ?? ''),
                'status' => (string) ($route['status'] ?? 'planned'),
                'date' => (string) ($route['date'] ?? ''),
            ];
        }
        $shippingNotes = [];
        foreach ($this->scopedNotes() as $note) {
            $shippingNotes[] = $note;
        }
        $deliveries = [];
        foreach ($this->scopedDeliveries() as $delivery) {
            $deliveries[] = $delivery;
        }

        return [
            'products' => $products,
            'warehouses' => $warehouses,
            'customers' => $customers,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'carriers' => Carriers::catalog(),
            'routes' => $routes,
            'shippingNotes' => $shippingNotes,
            'deliveries' => $deliveries,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function noteOverviews(): array
    {
        $rows = [];
        $deliveries = $this->scopedDeliveries()->keyBy('id');
        $shipments = $this->scopedShipments()->keyBy('id');
        foreach ($this->scopedNotes()->sortByDesc(fn (array $n) => (string) ($n['createdAt'] ?? ''))->values() as $note) {
            $delivery = ! empty($note['deliveryId']) ? $deliveries->get($note['deliveryId']) : null;
            $shipment = ! empty($note['shipmentId']) ? $shipments->get($note['shipmentId']) : null;
            $driverId = is_array($delivery) ? ($delivery['driverId'] ?? null) : (is_array($shipment) ? ($shipment['driverId'] ?? null) : null);
            $vehicleId = is_array($delivery) ? ($delivery['vehicleId'] ?? null) : (is_array($shipment) ? ($shipment['vehicleId'] ?? null) : null);
            $warehouse = $this->warehouseOf((string) ($note['warehouseId'] ?? ''));
            $status = (string) ($note['status'] ?? 'ready');
            $rows[] = [
                'note' => $note,
                'code' => (string) ($note['code'] ?? $note['id'] ?? ''),
                'statusLabel' => self::NOTE_LABEL[$status] ?? $status,
                'warehouseName' => (string) ($warehouse['name'] ?? $note['warehouseId'] ?? ''),
                'warehouseAddress' => (string) ($warehouse['address'] ?? ''),
                'driverName' => $driverId ? $this->personName((string) $driverId) : '',
                'driverPhone' => $driverId ? $this->driverPhone((string) $driverId) : '',
                'vehicleLabel' => $vehicleId ? $this->vehicleLabel((string) $vehicleId) : '',
                'date' => (string) ($note['createdAt'] ?? $note['loadedAt'] ?? (is_array($delivery) ? ($delivery['scheduledAt'] ?? '') : (is_array($shipment) ? ($shipment['date'] ?? '') : ''))),
                'linesCount' => count($note['lines'] ?? []),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function deliveryOverviews(): array
    {
        $routes = $this->scopedRoutes()->keyBy('id');
        $rows = [];
        foreach ($this->scopedDeliveries() as $delivery) {
            $warehouse = $this->warehouseOf((string) ($delivery['warehouseId'] ?? ''));
            $origin = $this->warehousePoint((string) ($delivery['warehouseId'] ?? ''));
            $location = is_array($delivery['location'] ?? null) ? $delivery['location'] : $origin;
            $path = ! empty($delivery['path']) && is_array($delivery['path'])
                ? $delivery['path']
                : array_values(array_filter([$origin, $location]));
            $progress = (float) ($delivery['progress'] ?? 0);
            $tracking = $this->trackingFrom($path, $progress, $delivery['currentPosition'] ?? null);
            $route = $routes->get($delivery['routeId'] ?? '');
            $status = (string) ($delivery['status'] ?? 'prepared');
            $rows[] = [
                ...$tracking,
                'delivery' => $delivery,
                'code' => (string) ($delivery['code'] ?? $delivery['id'] ?? ''),
                'statusLabel' => self::DELIVERY_LABEL[$status] ?? $status,
                'driverName' => $this->personName((string) ($delivery['driverId'] ?? '')),
                'driverPhone' => $this->driverPhone((string) ($delivery['driverId'] ?? '')),
                'vehicleLabel' => $this->vehicleLabel((string) ($delivery['vehicleId'] ?? '')),
                'warehouseName' => (string) ($warehouse['name'] ?? $origin['label'] ?? 'Entrepôt'),
                'warehouseAddress' => (string) ($warehouse['address'] ?? ''),
                'routeName' => is_array($route) ? (string) ($route['name'] ?? $delivery['routeId'] ?? '') : (string) ($delivery['routeId'] ?? ''),
                'lines' => $delivery['lines'] ?? [],
                'markers' => $this->deliveryMarkers($origin, $delivery, $tracking['vehicle'] ?? null),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function routeOverviews(): array
    {
        $deliveries = $this->scopedDeliveries();
        $rows = [];
        foreach ($this->scopedRoutes() as $route) {
            $warehouse = $this->warehouseOf((string) ($route['warehouseId'] ?? ''));
            $origin = $this->warehousePoint((string) ($route['warehouseId'] ?? ''));
            $stops = $deliveries
                ->filter(fn (array $d) => ($d['routeId'] ?? '') === ($route['id'] ?? ''))
                ->sortBy(fn (array $d) => (int) ($d['sequence'] ?? 0))
                ->values()
                ->all();
            $path = ! empty($route['path']) && is_array($route['path'])
                ? $route['path']
                : array_values(array_filter(array_merge([$origin], array_map(fn (array $s) => $s['location'] ?? null, $stops))));
            $doneCount = count(array_filter($stops, fn (array $s) => ($s['status'] ?? '') === 'delivered'));
            $progress = (float) ($route['progress'] ?? 0);
            if ($progress <= 0 && $stops) {
                $progress = round(($doneCount / count($stops)) * 100);
            }
            $active = collect($stops)->first(fn (array $s) => in_array($s['status'] ?? '', ['in_transit', 'at_risk'], true));
            $vehiclePos = $route['currentPosition'] ?? (is_array($active) ? ($active['currentPosition'] ?? null) : null);
            $tracking = $this->trackingFrom($path, $progress, $vehiclePos);
            $status = (string) ($route['status'] ?? 'planned');
            $rows[] = [
                ...$tracking,
                'route' => $route,
                'code' => (string) ($route['code'] ?? $route['id'] ?? ''),
                'statusLabel' => self::ROUTE_LABEL[$status] ?? $status,
                'driverName' => $this->personName((string) ($route['driverId'] ?? '')),
                'driverPhone' => $this->driverPhone((string) ($route['driverId'] ?? '')),
                'vehicleLabel' => $this->vehicleLabel((string) ($route['vehicleId'] ?? '')),
                'warehouseName' => (string) ($warehouse['name'] ?? $origin['label'] ?? 'Entrepôt'),
                'warehouseAddress' => (string) ($warehouse['address'] ?? ''),
                'stops' => $stops,
                'doneCount' => $doneCount,
                'remainingCount' => count($stops) - $doneCount,
                'markers' => $this->routeMarkers($origin, $stops, $tracking['vehicle'] ?? null),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function shipmentOverviews(): array
    {
        $deliveries = $this->scopedDeliveries();
        $routes = $this->scopedRoutes()->keyBy('id');
        $rows = [];
        foreach ($this->scopedShipments() as $shipment) {
            $originWh = $this->warehouseOf((string) ($shipment['originWarehouseId'] ?? ''));
            $destWh = ! empty($shipment['destinationWarehouseId'])
                ? $this->warehouseOf((string) $shipment['destinationWarehouseId'])
                : null;
            $route = $routes->get($shipment['routeId'] ?? '');
            $stops = $deliveries
                ->filter(fn (array $d) => ($d['routeId'] ?? '') && ($d['routeId'] ?? '') === ($shipment['routeId'] ?? ''))
                ->sortBy(fn (array $d) => (int) ($d['sequence'] ?? 0))
                ->values()
                ->all();
            $origin = is_array($shipment['origin'] ?? null)
                ? $shipment['origin']
                : $this->warehousePoint((string) ($shipment['originWarehouseId'] ?? ''));
            $destination = is_array($shipment['destinationLocation'] ?? null) ? $shipment['destinationLocation'] : null;
            if (! empty($shipment['path']) && is_array($shipment['path'])) {
                $path = $shipment['path'];
            } elseif (is_array($route) && ! empty($route['path'])) {
                $path = $route['path'];
            } else {
                $path = array_values(array_filter(array_merge(
                    [$origin],
                    array_map(fn (array $s) => $s['location'] ?? null, $stops),
                    [$destination],
                )));
            }
            $progress = (float) ($shipment['progress'] ?? (is_array($route) ? ($route['progress'] ?? 0) : 0));
            $vehiclePos = $shipment['currentPosition'] ?? (is_array($route) ? ($route['currentPosition'] ?? null) : null);
            $tracking = $this->trackingFrom($path, $progress, $vehiclePos);
            $status = (string) ($shipment['status'] ?? 'preparing');
            $rows[] = [
                ...$tracking,
                'shipment' => $shipment,
                'code' => (string) ($shipment['code'] ?? $shipment['id'] ?? ''),
                'statusLabel' => self::SHIPMENT_LABEL[$status] ?? $status,
                'driverName' => $this->personName((string) ($shipment['driverId'] ?? '')),
                'driverPhone' => $this->driverPhone((string) ($shipment['driverId'] ?? '')),
                'vehicleLabel' => $this->vehicleLabel((string) ($shipment['vehicleId'] ?? '')),
                'originName' => (string) ($originWh['name'] ?? $origin['label'] ?? 'Départ'),
                'originAddress' => (string) ($originWh['address'] ?? ''),
                'destinationName' => (string) ($destWh['name'] ?? $shipment['destination'] ?? ''),
                'destinationAddress' => (string) ($destWh['address'] ?? ($stops ? (string) ($stops[count($stops) - 1]['address'] ?? '') : '')),
                'routeName' => is_array($route) ? (string) ($route['name'] ?? '') : null,
                'lines' => $shipment['lines'] ?? [],
                'stops' => $stops,
                'markers' => $this->shipmentMarkers($origin, $destination, $stops, $shipment, $tracking['vehicle'] ?? null),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function vehicleRows(): array
    {
        $drivers = $this->scopedDrivers();
        $rows = [];
        foreach ($this->scopedVehicles() as $vehicle) {
            $id = (string) ($vehicle['id'] ?? '');
            $driver = $drivers->first(fn (array $d) => ($d['vehicleId'] ?? '') === $id);
            $status = (string) ($vehicle['status'] ?? 'available');
            $rows[] = [
                'vehicle' => $vehicle,
                'statusLabel' => self::VEHICLE_LABEL[$status] ?? $status,
                'warehouseName' => (string) ($this->warehouseOf((string) ($vehicle['warehouseId'] ?? ''))['name'] ?? $vehicle['warehouseId'] ?? ''),
                'driverName' => is_array($driver) ? $this->personName((string) ($driver['id'] ?? '')) : 'Aucun chauffeur affecté',
                'driverId' => is_array($driver) ? (string) ($driver['id'] ?? '') : null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function driverRows(): array
    {
        $vehicles = $this->scopedVehicles()->keyBy('id');
        $rows = [];
        foreach ($this->scopedDrivers() as $driver) {
            $vehicle = ! empty($driver['vehicleId']) ? $vehicles->get($driver['vehicleId']) : null;
            $status = (string) ($driver['status'] ?? 'available');
            $rows[] = [
                'driver' => $driver,
                'name' => $this->personName((string) ($driver['id'] ?? '')),
                'statusLabel' => self::DRIVER_LABEL[$status] ?? $status,
                'vehicleLabel' => is_array($vehicle)
                    ? ((string) ($vehicle['name'] ?? '').' · '.(string) ($vehicle['plate'] ?? ''))
                    : 'Aucun véhicule affecté',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{lat: float|int, lng: float|int, label?: string}>  $path
     * @param  array<string, mixed>|null  $vehicle
     * @return array{path: list<array<string, mixed>>, trail: list<array<string, mixed>>, remaining: list<array<string, mixed>>, vehicle: array<string, mixed>|null, progress: float}
     */
    private function trackingFrom(array $path, float $progress, ?array $vehicle): array
    {
        $ratio = min(1, max(0, $progress / 100));
        $safePath = $path ?: ($vehicle ? [$vehicle] : []);
        $split = Geo::splitPath($safePath, $ratio);
        $position = $vehicle ?? ($safePath ? Geo::pointAlong($safePath, $ratio) : null);

        return [
            'path' => $safePath,
            'trail' => $split['done'],
            'remaining' => $split['rest'],
            'vehicle' => $position,
            'progress' => $progress,
        ];
    }

    /**
     * @param  array{lat: float|int, lng: float|int, label?: string}  $origin
     * @param  array<string, mixed>  $delivery
     * @param  array<string, mixed>|null  $vehicle
     * @return list<array<string, mixed>>
     */
    private function deliveryMarkers(array $origin, array $delivery, ?array $vehicle): array
    {
        $location = is_array($delivery['location'] ?? null) ? $delivery['location'] : $origin;
        $markers = [
            ['id' => 'origin', ...$origin, 'kind' => 'origin', 'label' => $origin['label'] ?? null, 'tone' => 'origin'],
            [
                'id' => (string) ($delivery['id'] ?? 'dest'),
                ...$location,
                'kind' => 'destination',
                'label' => (string) ($delivery['client'] ?? ''),
                'tone' => $this->stopTone((string) ($delivery['status'] ?? '')),
            ],
        ];
        if ($vehicle) {
            $markers[] = ['id' => 'vehicle', ...$vehicle, 'kind' => 'vehicle', 'label' => 'Véhicule'];
        }

        return $markers;
    }

    /**
     * @param  array{lat: float|int, lng: float|int, label?: string}  $origin
     * @param  list<array<string, mixed>>  $stops
     * @param  array<string, mixed>|null  $vehicle
     * @return list<array<string, mixed>>
     */
    private function routeMarkers(array $origin, array $stops, ?array $vehicle): array
    {
        $markers = [
            ['id' => 'origin', ...$origin, 'kind' => 'origin', 'label' => $origin['label'] ?? null, 'tone' => 'origin'],
        ];
        foreach ($stops as $stop) {
            $location = is_array($stop['location'] ?? null) ? $stop['location'] : null;
            if (! $location) {
                continue;
            }
            $markers[] = [
                'id' => (string) ($stop['id'] ?? ''),
                ...$location,
                'kind' => 'stop',
                'label' => (string) ($stop['client'] ?? ''),
                'sequence' => (int) ($stop['sequence'] ?? 0),
                'tone' => $this->stopTone((string) ($stop['status'] ?? '')),
            ];
        }
        if ($vehicle) {
            $markers[] = ['id' => 'vehicle', ...$vehicle, 'kind' => 'vehicle', 'label' => 'Véhicule'];
        }

        return $markers;
    }

    /**
     * @param  array{lat: float|int, lng: float|int, label?: string}  $origin
     * @param  array<string, mixed>|null  $destination
     * @param  list<array<string, mixed>>  $stops
     * @param  array<string, mixed>  $shipment
     * @param  array<string, mixed>|null  $vehicle
     * @return list<array<string, mixed>>
     */
    private function shipmentMarkers(array $origin, ?array $destination, array $stops, array $shipment, ?array $vehicle): array
    {
        $markers = [
            ['id' => 'origin', ...$origin, 'kind' => 'origin', 'label' => $origin['label'] ?? null, 'tone' => 'origin'],
        ];
        foreach ($stops as $stop) {
            $location = is_array($stop['location'] ?? null) ? $stop['location'] : null;
            if (! $location) {
                continue;
            }
            $markers[] = [
                'id' => (string) ($stop['id'] ?? ''),
                ...$location,
                'kind' => 'stop',
                'label' => (string) ($stop['client'] ?? ''),
                'sequence' => (int) ($stop['sequence'] ?? 0),
                'tone' => $this->stopTone((string) ($stop['status'] ?? '')),
            ];
        }
        if (! $stops && $destination) {
            $markers[] = [
                'id' => 'destination',
                ...$destination,
                'kind' => 'destination',
                'label' => (string) ($shipment['destination'] ?? ''),
                'tone' => 'pending',
            ];
        }
        if ($vehicle) {
            $markers[] = ['id' => 'vehicle', ...$vehicle, 'kind' => 'vehicle', 'label' => 'Véhicule'];
        }

        return $markers;
    }

    private function stopTone(string $status): string
    {
        if ($status === 'delivered') {
            return 'done';
        }
        if (in_array($status, ['at_risk', 'failed'], true)) {
            return 'risk';
        }
        if ($status === 'in_transit') {
            return 'current';
        }

        return 'pending';
    }

    /** @return array<string, mixed>|null */
    private function warehouseOf(string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $row = $this->warehouses->find($id);

        return is_array($row) ? $row : null;
    }

    /** @return array{lat: float, lng: float, label: string} */
    private function warehousePoint(string $id): array
    {
        $warehouse = $this->warehouseOf($id);
        if ($warehouse && isset($warehouse['lat'], $warehouse['lng'])) {
            return [
                'lat' => (float) $warehouse['lat'],
                'lng' => (float) $warehouse['lng'],
                'label' => 'Entrepôt '.((string) ($warehouse['name'] ?? $id)),
            ];
        }

        return ['lat' => 33.5731, 'lng' => -7.5898, 'label' => (string) ($warehouse['name'] ?? 'Entrepôt')];
    }

    private function personName(string $driverId): string
    {
        if ($driverId === '') {
            return '';
        }
        $driver = $this->scopedDrivers()->first(fn (array $d) => ($d['id'] ?? '') === $driverId);
        if (! is_array($driver)) {
            return $driverId;
        }
        $user = $this->users->all()->first(fn (array $u) => ($u['id'] ?? '') === ($driver['userId'] ?? ''));
        if (is_array($user)) {
            return trim(((string) ($user['firstName'] ?? '')).' '.((string) ($user['lastName'] ?? '')));
        }

        return $driverId;
    }

    private function driverPhone(string $driverId): string
    {
        $driver = $this->scopedDrivers()->first(fn (array $d) => ($d['id'] ?? '') === $driverId);

        return is_array($driver) ? (string) ($driver['phone'] ?? '') : '';
    }

    private function vehicleLabel(string $vehicleId): string
    {
        if ($vehicleId === '') {
            return '';
        }
        $vehicle = $this->scopedVehicles()->first(fn (array $v) => ($v['id'] ?? '') === $vehicleId);
        if (! is_array($vehicle)) {
            return $vehicleId;
        }

        return ((string) ($vehicle['name'] ?? '')).' · '.((string) ($vehicle['plate'] ?? ''));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scopedNotes(): Collection
    {
        return $this->logistics->shippingNotes()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scopedDeliveries(): Collection
    {
        return $this->deliveries->list()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scopedRoutes(): Collection
    {
        return $this->logistics->routes()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scopedShipments(): Collection
    {
        return $this->logistics->shipments()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scopedVehicles(): Collection
    {
        return $this->logistics->vehicles()->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scopedDrivers(): Collection
    {
        return $this->logistics->drivers()->values();
    }

    /** @return list<string>|null */
    private function userWarehouseScope(): ?array
    {
        $ids = array_values(array_filter($this->tenant->user()?->warehouse_ids ?? []));

        return $ids ?: null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): string  $idOf
     * @return array{page: int, pageSize: int, total: int, pages: int, items: list<array<string, mixed>>, focused: array<string, mixed>|null}
     */
    private function paginate(array $items, int $page, int $pageSize, ?string $focusId, callable $idOf): array
    {
        $total = count($items);
        $pages = max(1, (int) ceil($total / max(1, $pageSize)));
        $page = max(1, min($page, $pages));
        $focused = null;
        if ($focusId) {
            foreach ($items as $index => $item) {
                if ($idOf($item) === $focusId) {
                    $focused = $item;
                    $page = intdiv($index, $pageSize) + 1;
                    break;
                }
            }
        }

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'pages' => $pages,
            'items' => array_values(array_slice($items, ($page - 1) * $pageSize, $pageSize)),
            'focused' => $focused,
        ];
    }
}
