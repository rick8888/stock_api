<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\DriverRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\ShipmentRepository;
use App\Repositories\Eloquent\ShippingNoteRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\TaskItemRepository;
use App\Repositories\Eloquent\VehicleRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LogisticsService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly RouteRepository $routes,
        private readonly VehicleRepository $vehicles,
        private readonly DriverRepository $drivers,
        private readonly ShippingNoteRepository $notes,
        private readonly WarehouseRepository $warehouses,
        private readonly ProductRepository $products,
        private readonly DeliveryRepository $deliveries,
        private readonly TaskItemRepository $tasks,
        private readonly StockItemRepository $stockItems,
        private readonly StockService $stock,
        private readonly UserService $users,
        private readonly OpsNotifier $notifier,
    ) {}

    public function shipments(): Collection
    {
        return $this->shipments->all();
    }

    public function routes(): Collection
    {
        return $this->routes->all();
    }

    public function vehicles(): Collection
    {
        return $this->vehicles->all();
    }

    public function drivers(): Collection
    {
        return $this->drivers->all();
    }

    public function shippingNotes(): Collection
    {
        return $this->notes->all();
    }

    public function createShippingNote(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $note = $this->notes->put([
                'id' => Uid::make('bl'),
                ...$this->assembleNote($input),
            ]);
            $this->notifyNote($note);
            $this->notifier->audit('Bon de livraison préparé', 'shipping_note', $note['id'], $input['createdByUserId'] ?? '');

            return $note;
        });
    }

    public function updateShippingNote(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $note = $this->notes->findOrFail($id, 'Bon de livraison introuvable');
            if (($note['status'] ?? '') !== 'ready') {
                throw new DomainException('Ce bon est déjà affecté');
            }
            $merged = [
                'warehouseId' => $input['warehouseId'] ?? $note['warehouseId'],
                'client' => $input['client'] ?? $note['client'],
                'address' => $input['address'] ?? $note['address'],
                'city' => $input['city'] ?? $note['city'],
                'notes' => $input['notes'] ?? $note['notes'] ?? null,
                'createdByUserId' => $note['createdByUserId'] ?? null,
                'lines' => $input['lines'] ?? collect($note['lines'] ?? [])->map(fn (array $line) => [
                    'productId' => $line['productId'],
                    'quantity' => $line['quantity'],
                ])->all(),
            ];

            return $this->notes->put(['id' => $id, ...$this->assembleNote($merged, $note)]);
        });
    }

    public function createVehicle(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $vehicle = $this->vehicles->put([
                'id' => Uid::make('veh'),
                'name' => trim($input['name'] ?? ''),
                'plate' => strtoupper(trim($input['plate'] ?? '')),
                'warehouseId' => $input['warehouseId'],
                'status' => $input['status'] ?? 'available',
            ]);
            if (! empty($input['driverId'])) {
                $this->assignVehicle($input['driverId'], $vehicle['id']);
            }

            return $vehicle;
        });
    }

    public function updateVehicle(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $vehicle = $this->vehicles->findOrFail($id, 'Véhicule introuvable');
            if (! empty($input['name'])) {
                $vehicle['name'] = trim($input['name']);
            }
            if (! empty($input['plate'])) {
                $vehicle['plate'] = strtoupper(trim($input['plate']));
            }
            if (! empty($input['warehouseId'])) {
                $vehicle['warehouseId'] = $input['warehouseId'];
            }
            if (! empty($input['status'])) {
                $vehicle['status'] = $input['status'];
            }
            $this->vehicles->put($vehicle);
            if (array_key_exists('driverId', $input)) {
                $this->assignVehicle($input['driverId'] ?: null, $vehicle['id']);
            }

            return $vehicle;
        });
    }

    public function createDriver(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $warehouseId = $input['warehouseId'] ?? ($this->warehouses->all()->first()['id'] ?? 'wh-casa');
            $user = $this->users->create([
                'firstName' => $input['firstName'] ?? '',
                'lastName' => $input['lastName'] ?? '',
                'email' => $input['email'] ?? null,
                'password' => 'demo',
                'role' => 'DRIVER',
                'title' => 'Chauffeur',
                'warehouseIds' => $warehouseId ? [$warehouseId] : [],
                'phone' => $input['phone'] ?? '',
            ]);
            $driver = $this->drivers->find($user['driverId'] ?? '');
            if (! $driver) {
                throw new DomainException('Chauffeur introuvable');
            }
            $driver['phone'] = trim($input['phone'] ?? '');
            $driver['status'] = $input['status'] ?? 'available';
            $driver['vehicleId'] = $input['vehicleId'] ?? null;
            $this->drivers->put($driver);
            if (! empty($input['vehicleId'])) {
                $this->assignVehicle($driver['id'], $input['vehicleId']);
            }

            return $this->drivers->find($driver['id']);
        });
    }

    public function updateDriver(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $driver = $this->drivers->findOrFail($id, 'Chauffeur introuvable');
            $userPatch = [];
            if (! empty($input['firstName'])) {
                $userPatch['firstName'] = $input['firstName'];
            }
            if (! empty($input['lastName'])) {
                $userPatch['lastName'] = $input['lastName'];
            }
            if (! empty($input['email'])) {
                $userPatch['email'] = $input['email'];
            }
            if (! empty($input['warehouseId'])) {
                $userPatch['warehouseIds'] = [$input['warehouseId']];
            }
            if ($userPatch) {
                $this->users->update($driver['userId'], $userPatch);
            }
            if (! empty($input['phone'])) {
                $driver['phone'] = trim($input['phone']);
            }
            if (! empty($input['status'])) {
                $driver['status'] = $input['status'];
            }
            $this->drivers->put($driver);
            if (array_key_exists('vehicleId', $input)) {
                $this->assignVehicle($driver['id'], $input['vehicleId'] ?: null);
                $driver = $this->drivers->find($id);
            }

            return $driver;
        });
    }

    public function createRoute(array $input): array
    {
        $warehouse = $this->warehouses->find($input['warehouseId'] ?? '');
        $point = [
            'lat' => $warehouse['lat'] ?? 33.5731,
            'lng' => $warehouse['lng'] ?? -7.5898,
            'label' => $warehouse['name'] ?? 'Entrepôt',
        ];

        return $this->routes->put([
            'id' => Uid::make('rt'),
            'name' => $input['name'],
            'date' => $input['date'],
            'driverId' => $input['driverId'],
            'vehicleId' => $input['vehicleId'],
            'warehouseId' => $input['warehouseId'],
            'status' => $input['status'] ?? 'planned',
            'distanceKm' => 1,
            'path' => [$point],
            'currentPosition' => $point,
            'progress' => 0,
        ]);
    }

    public function updateRoute(string $id, array $input): array
    {
        $route = $this->routes->findOrFail($id, 'Tournée introuvable');

        return $this->routes->merge($id, array_filter([
            'name' => $input['name'] ?? null,
            'date' => $input['date'] ?? null,
            'driverId' => $input['driverId'] ?? null,
            'vehicleId' => $input['vehicleId'] ?? null,
            'warehouseId' => $input['warehouseId'] ?? null,
            'status' => $input['status'] ?? null,
        ], fn ($value) => $value !== null), 'Tournée introuvable');
    }

    public function createShipment(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $seq = $this->shipments->all()->count() + 80;
            $origin = $this->warehouses->find($input['originWarehouseId'] ?? '');
            $dest = $this->warehouses->find($input['destinationWarehouseId'] ?? '');
            $originPoint = [
                'lat' => $origin['lat'] ?? 33.5731,
                'lng' => $origin['lng'] ?? -7.5898,
                'label' => $origin['name'] ?? 'Origine',
            ];
            $destPoint = [
                'lat' => $dest['lat'] ?? 34.02,
                'lng' => $dest['lng'] ?? -6.84,
                'label' => $input['destination'] ?? ($dest['name'] ?? 'Destination'),
            ];
            $noteIds = $input['shippingNoteIds'] ?? [];
            $lines = $this->aggregateNotes($noteIds);
            $shipment = $this->shipments->put([
                'id' => Uid::make('shp'),
                'originWarehouseId' => $input['originWarehouseId'],
                'destination' => $input['destination'] ?? ($dest['name'] ?? ''),
                'destinationWarehouseId' => $input['destinationWarehouseId'] ?? null,
                'vehicleId' => $input['vehicleId'],
                'driverId' => $input['driverId'],
                'routeId' => $input['routeId'] ?? null,
                'date' => $input['date'],
                'status' => $input['status'] ?? 'preparing',
                'code' => 'EX-2026-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'origin' => $originPoint,
                'destinationLocation' => $destPoint,
                'path' => [$originPoint, $destPoint],
                'currentPosition' => $originPoint,
                'progress' => 0,
                'lines' => $lines,
                'eta' => $input['eta'] ?? null,
                'notes' => $input['notes'] ?? null,
                'shippingNoteIds' => $noteIds,
            ]);
            $this->bindShipmentNotes($shipment);

            return $shipment;
        });
    }

    public function updateShipment(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $shipment = $this->shipments->findOrFail($id, 'Expédition introuvable');
            $shipment = array_merge($shipment, array_filter($input, fn ($value) => $value !== null));
            if (! empty($input['shippingNoteIds'])) {
                $shipment['lines'] = $this->aggregateNotes($input['shippingNoteIds']);
            }
            $this->shipments->put($shipment);
            $this->bindShipmentNotes($shipment);

            return $shipment;
        });
    }

    public function loadShippingNote(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $note = $this->notes->findOrFail($id, 'Bon introuvable');
            if (! empty($note['loadedAt'])) {
                throw new DomainException('Ce bon est déjà chargé.');
            }
            $now = now()->toIso8601String();
            foreach ($note['lines'] ?? [] as $line) {
                $item = $this->stock->tryLine($line['productId'], $note['warehouseId']);
                if (! $item || (int) $item['quantity'] < (int) $line['quantity']) {
                    throw new DomainException('Stock insuffisant pour '.($line['productName'] ?? $line['productId']));
                }
                $item['quantity'] = (int) $item['quantity'] - (int) $line['quantity'];
                $this->stockItems->put($item);
                $this->stock->move(
                    'issue',
                    $line['productId'],
                    (int) $line['quantity'],
                    $userId,
                    'Chargement '.$note['code'],
                    $note['warehouseId'],
                    null,
                    $note['code'],
                );
            }
            $note['loadedAt'] = $now;
            $note['loadedByUserId'] = $userId;
            $this->notes->put($note);
            $this->notifier->audit('Bon chargé', 'shipping_note', $note['id'], $userId);

            return $note;
        });
    }

    private function assembleNote(array $input, ?array $existing = null): array
    {
        $warehouse = $this->warehouses->find($input['warehouseId'] ?? '');
        if (! $warehouse) {
            throw new DomainException('Entrepôt introuvable');
        }
        $client = trim($input['client'] ?? '');
        $address = trim($input['address'] ?? '');
        $city = trim(($input['city'] ?? '') ?: ($warehouse['city'] ?? ''));
        if (strlen($client) < 2 || strlen($address) < 2 || $city === '') {
            throw new DomainException('Client et adresse sont requis');
        }
        $lines = [];
        foreach ($input['lines'] ?? [] as $line) {
            $product = $this->products->find($line['productId'] ?? '');
            if (! $product || (int) ($line['quantity'] ?? 0) <= 0) {
                continue;
            }
            $lines[] = [
                'productId' => $product['id'],
                'productName' => $product['name'],
                'sku' => $product['sku'] ?? null,
                'quantity' => (int) $line['quantity'],
                'unit' => $product['unit'],
            ];
        }
        if (! $lines) {
            throw new DomainException('Ajoutez au moins un article');
        }
        $numbers = $this->notes->all()->map(fn (array $note) => (int) substr($note['code'] ?? '0', -5))->filter()->all();
        $next = max(900, $numbers ? max($numbers) : 0) + 1;

        return [
            'code' => $existing['code'] ?? 'BL-2026-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'warehouseId' => $warehouse['id'],
            'client' => $client,
            'address' => $address,
            'city' => $city,
            'lines' => $lines,
            'status' => $existing['status'] ?? 'ready',
            'deliveryId' => $existing['deliveryId'] ?? null,
            'shipmentId' => $existing['shipmentId'] ?? null,
            'notes' => trim((string) ($input['notes'] ?? $existing['notes'] ?? '')) ?: null,
            'createdAt' => $existing['createdAt'] ?? now()->toIso8601String(),
            'createdByUserId' => $existing['createdByUserId'] ?? ($input['createdByUserId'] ?? null),
        ];
    }

    private function notifyNote(array $note): void
    {
        $warehouse = $this->warehouses->find($note['warehouseId']);
        $warehouseName = $warehouse['name'] ?? 'entrepôt';
        $this->notifier->alert([
            'title' => ($note['code'] ?? 'BL').' prêt à expédier',
            'message' => ($note['client'] ?? '').' — '.($note['city'] ?? '').'. Bon préparé à '.$warehouseName.'.',
            'severity' => 'info',
            'domain' => 'logistics',
            'relatedId' => $note['id'],
            'warehouseId' => $note['warehouseId'],
            'roles' => ['DISPATCHER'],
        ]);
        $dispatchers = $this->users->list()->filter(function (array $user) use ($note) {
            if ($user['role'] !== 'DISPATCHER') {
                return false;
            }
            $ids = $user['warehouseIds'] ?? [];

            return ! $ids || in_array($note['warehouseId'], $ids, true);
        });
        if ($dispatchers->isEmpty()) {
            $dispatchers = $this->users->list()->filter(fn (array $user) => $user['role'] === 'DISPATCHER');
        }
        foreach ($dispatchers as $user) {
            $task = $this->tasks->put([
                'id' => Uid::make('tsk'),
                'title' => 'Affecter '.$note['code'],
                'description' => ($note['client'] ?? '').' — '.($note['city'] ?? '').'. Bon prêt à '.$warehouseName.', à placer sur une tournée ou une expédition.',
                'assigneeId' => $user['id'],
                'dueAt' => '2026-08-26T17:00:00.000Z',
                'status' => 'todo',
                'priority' => 'medium',
                'relatedType' => 'shipping_note',
                'relatedId' => $note['id'],
            ]);
            $this->notifier->notify('Bon de livraison prêt', ($note['code'] ?? 'BL').' · '.($note['client'] ?? '').' ('.$warehouseName.')', 'info', $user['id']);
            unset($task);
        }
    }

    private function assignVehicle(?string $driverId, ?string $vehicleId): void
    {
        if ($vehicleId) {
            foreach ($this->drivers->all() as $driver) {
                if (($driver['vehicleId'] ?? null) === $vehicleId && $driver['id'] !== $driverId) {
                    $driver['vehicleId'] = null;
                    $this->drivers->put($driver);
                }
            }
        }
        if (! $driverId) {
            return;
        }
        $driver = $this->drivers->find($driverId);
        if (! $driver) {
            return;
        }
        $driver['vehicleId'] = $vehicleId ?: null;
        $this->drivers->put($driver);
    }

    private function aggregateNotes(array $noteIds): array
    {
        $aggregated = [];
        foreach ($noteIds as $id) {
            $note = $this->notes->find($id);
            foreach ($note['lines'] ?? [] as $line) {
                $key = $line['productId'];
                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = $line;
                } else {
                    $aggregated[$key]['quantity'] += $line['quantity'];
                }
            }
        }

        return array_values($aggregated);
    }

    private function bindShipmentNotes(array $shipment): void
    {
        $ids = $shipment['shippingNoteIds'] ?? [];
        foreach ($this->notes->all() as $note) {
            if (($note['shipmentId'] ?? null) === $shipment['id'] && ! in_array($note['id'], $ids, true)) {
                $note['shipmentId'] = null;
                if (($note['status'] ?? '') === 'assigned' && empty($note['deliveryId'])) {
                    $note['status'] = 'ready';
                }
                $this->notes->put($note);
            }
            if (in_array($note['id'], $ids, true)) {
                $note['shipmentId'] = $shipment['id'];
                if (($note['status'] ?? '') === 'ready') {
                    $note['status'] = 'assigned';
                }
                $this->notes->put($note);
            }
        }
        foreach ($this->tasks->all() as $task) {
            if (($task['relatedType'] ?? '') === 'shipping_note' && in_array($task['relatedId'] ?? '', $ids, true) && ($task['status'] ?? '') !== 'done') {
                $task['status'] = 'done';
                $this->tasks->put($task);
            }
        }
    }
}
