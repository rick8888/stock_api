<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\CustomerRepository;
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
use App\Support\DemoDate;
use App\Support\Geo;
use App\Support\StatusTransition;
use App\Support\Tenant;
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
        private readonly Tenant $tenant,
        private readonly CustomerRepository $customers,
        private readonly DeliveryService $deliveryService,
    ) {}

    public function shipments(): Collection
    {
        $items = $this->tenant->bypassTenantFilter()
            ? $this->shipments->all()
            : $this->shipments->forWarehouses($this->tenant->companyWarehouseIds());

        return $items
            ->map(function (array $shipment) {
                if (! empty($shipment['date'])) {
                    $shipment['date'] = DemoDate::rebase($shipment['date']);
                }
                if (! empty($shipment['eta'])) {
                    $shipment['eta'] = DemoDate::rebase($shipment['eta']);
                }

                return $shipment;
            })
            ->values();
    }

    public function routes(): Collection
    {
        $items = $this->tenant->bypassTenantFilter()
            ? $this->routes->all()
            : $this->routes->forWarehouses($this->tenant->companyWarehouseIds());

        return $items
            ->map(function (array $route) {
                if (! empty($route['date'])) {
                    $route['date'] = DemoDate::rebase($route['date']);
                }

                return $route;
            })
            ->values();
    }

    public function vehicles(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->vehicles->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->vehicles->all();
    }

    public function drivers(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->drivers->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->drivers->all();
    }

    public function shippingNotes(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->notes->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->notes->all();
    }

    public function createShippingNote(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $this->tenant->assertWarehouseAccess($input['warehouseId'] ?? '');
            $actorId = $input['createdByUserId'] ?? $this->tenant->actorId();
            $note = $this->notes->put($this->tenant->stamp([
                'id' => Uid::make('bl'),
                ...$this->assembleNote([...$input, 'createdByUserId' => $actorId]),
            ]));
            $this->notifyNote($note);
            $this->notifier->audit('Bon de livraison préparé', 'shipping_note', $note['id'], $actorId);

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
                'carrierId' => array_key_exists('carrierId', $input) ? $input['carrierId'] : ($note['carrierId'] ?? null),
                'trackingNumber' => array_key_exists('trackingNumber', $input) ? $input['trackingNumber'] : ($note['trackingNumber'] ?? null),
                'createdByUserId' => $note['createdByUserId'] ?? null,
                'lines' => $input['lines'] ?? collect($note['lines'] ?? [])->map(fn (array $line) => [
                    'productId' => $line['productId'],
                    'quantity' => $line['quantity'],
                ])->all(),
            ];

            return $this->notes->put(['id' => $id, ...$this->assembleNote($merged, $note)]);
        });
    }

    public function assignCarrier(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $note = $this->notes->findOrFail($id, 'Bon de livraison introuvable');
            $carrier = \App\Support\Carriers::find((string) ($input['carrierId'] ?? ''));
            if (! $carrier) {
                throw new DomainException('Transporteur introuvable');
            }
            $note['carrierId'] = $carrier['id'];
            $note['carrierName'] = $carrier['name'];
            $tracking = trim((string) ($input['trackingNumber'] ?? ''));
            $book = array_key_exists('book', $input) ? (bool) $input['book'] : true;
            if ($tracking !== '') {
                $note['trackingNumber'] = $tracking;
            } elseif ($book) {
                $note['trackingNumber'] = \App\Support\Carriers::bookTracking($carrier, (string) ($note['code'] ?? 'BL'));
                $note['bookedAt'] = now()->toIso8601String();
            }
            $this->notes->put($note);
            $this->notifier->audit('Transporteur affecté', 'shipping_note', $note['id'], $this->tenant->actorId());

            return $note;
        });
    }

    public function markShippingNoteLabelPrinted(string $id): array
    {
        $note = $this->notes->findOrFail($id, 'Bon de livraison introuvable');
        $note['labelPrintedAt'] = now()->toIso8601String();

        return $this->notes->put($note);
    }

    public function carriers(): array
    {
        return \App\Support\Carriers::catalog();
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
        return DB::transaction(function () use ($input) {
            $this->tenant->assertWarehouseAccess($input['warehouseId'] ?? '');
            $warehouse = $this->warehouses->find($input['warehouseId'] ?? '');
            $point = [
                'lat' => (float) ($warehouse['lat'] ?? 33.5731),
                'lng' => (float) ($warehouse['lng'] ?? -7.5898),
                'label' => $warehouse['name'] ?? 'Entrepôt',
            ];

            $route = $this->routes->put($this->tenant->stamp([
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
            ]));

            if (array_key_exists('stops', $input) && is_array($input['stops'])) {
                $this->syncRouteStops($route, $input['stops']);
                $route = $this->refreshRouteGeometry($route);
            }

            return $route;
        });
    }

    public function updateRoute(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $route = $this->routes->findOrFail($id, 'Tournée introuvable');
            $this->tenant->assertWarehouseAccess($route['warehouseId'] ?? '');
            if (! empty($input['warehouseId'])) {
                $this->tenant->assertWarehouseAccess($input['warehouseId']);
            }

            if (! empty($input['status'])) {
                StatusTransition::assert('route', (string) ($route['status'] ?? 'planned'), (string) $input['status'], 'Tournée');
            }

            $route = $this->routes->merge($id, array_filter([
                'name' => $input['name'] ?? null,
                'date' => $input['date'] ?? null,
                'driverId' => $input['driverId'] ?? null,
                'vehicleId' => $input['vehicleId'] ?? null,
                'warehouseId' => $input['warehouseId'] ?? null,
                'status' => $input['status'] ?? null,
            ], fn ($value) => $value !== null), 'Tournée introuvable');

            if (array_key_exists('stops', $input) && is_array($input['stops'])) {
                $this->syncRouteStops($route, $input['stops']);
            } else {
                $this->relinkRouteDeliveries($route);
            }

            return $this->refreshRouteGeometry($route);
        });
    }

    /**
     * Démarre la préparation (picking) d'un BL : ready → picking.
     */
    public function startPicking(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $note = $this->notes->findOrFail($id, 'Bon introuvable');
            $this->tenant->assertWarehouseAccess($note['warehouseId'] ?? '');
            $status = $note['status'] ?? 'ready';
            StatusTransition::assert('shipping_note', $status, 'picking', 'Bon de livraison');
            $note['status'] = 'picking';
            $this->notes->put($note);
            $this->notifier->audit('Préparation démarrée', 'shipping_note', $note['id'], $this->tenant->actorId());

            return $note;
        });
    }

    public function createShipment(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $this->tenant->assertWarehouseAccess($input['originWarehouseId'] ?? '');
            if (! empty($input['destinationWarehouseId'])) {
                $this->tenant->assertWarehouseAccess($input['destinationWarehouseId']);
            }
            $seq = $this->shipments->countAll() + 80;
            $year = now()->format('Y');
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
            $shipment = $this->shipments->put($this->tenant->stamp([
                'id' => Uid::make('shp'),
                'originWarehouseId' => $input['originWarehouseId'],
                'destination' => $input['destination'] ?? ($dest['name'] ?? ''),
                'destinationWarehouseId' => $input['destinationWarehouseId'] ?? null,
                'vehicleId' => $input['vehicleId'],
                'driverId' => $input['driverId'],
                'routeId' => $input['routeId'] ?? null,
                'date' => $input['date'],
                'status' => $input['status'] ?? 'preparing',
                'code' => 'EX-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'origin' => $originPoint,
                'destinationLocation' => $destPoint,
                'path' => [$originPoint, $destPoint],
                'currentPosition' => $originPoint,
                'progress' => 0,
                'lines' => $lines,
                'eta' => $input['eta'] ?? null,
                'notes' => $input['notes'] ?? null,
                'shippingNoteIds' => $noteIds,
                'stockReceivedAt' => null,
            ]));
            $this->bindShipmentNotes($shipment);

            return $shipment;
        });
    }

    public function updateShipment(string $id, array $input, ?string $userId = null): array
    {
        return DB::transaction(function () use ($id, $input, $userId) {
            $shipment = $this->shipments->findOrFail($id, 'Expédition introuvable');
            $this->tenant->assertWarehouseAccess($shipment['originWarehouseId'] ?? '');
            $previousStatus = (string) ($shipment['status'] ?? 'preparing');
            if (! empty($input['status'])) {
                StatusTransition::assert('shipment', $previousStatus, (string) $input['status'], 'Expédition');
            }
            $shipment = array_merge($shipment, array_filter($input, fn ($value) => $value !== null));
            if (! empty($input['shippingNoteIds'])) {
                $shipment['lines'] = $this->aggregateNotes($input['shippingNoteIds']);
            }
            $actorId = $userId ?: $this->tenant->actorId();
            if (($shipment['status'] ?? '') === 'done' && $previousStatus !== 'done') {
                $shipment = $this->receiveTransferShipment($shipment, $actorId);
            }
            if (! empty($shipment['routeId'])) {
                $shipment = $this->applyRouteTrackingToShipment($shipment);
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
            $this->tenant->assertWarehouseAccess($note['warehouseId'] ?? '');
            if (! empty($note['loadedAt'])) {
                throw new DomainException('Ce bon est déjà chargé.');
            }
            $status = $note['status'] ?? 'ready';
            if (! in_array($status, ['ready', 'picking', 'loaded', 'assigned'], true)) {
                throw new DomainException('Ce bon ne peut plus être chargé.');
            }
            $now = now()->toIso8601String();
            $shipment = $this->shipments->find($note['shipmentId'] ?? '');
            $destinationWarehouseId = $shipment['destinationWarehouseId'] ?? null;
            if (empty($note['stockReserved'])) {
                foreach ($note['lines'] ?? [] as $line) {
                    $this->stock->reserve($line['productId'], $note['warehouseId'], (int) $line['quantity']);
                }
                $note['stockReserved'] = true;
            }
            foreach ($note['lines'] ?? [] as $line) {
                $qty = (int) $line['quantity'];
                if ($destinationWarehouseId) {
                    $this->stock->debitTransferOutbound(
                        $line['productId'],
                        $note['warehouseId'],
                        $destinationWarehouseId,
                        $qty,
                        $userId,
                        'Chargement transfert '.$note['code'],
                        $note['code'],
                        true,
                    );
                } else {
                    $this->stock->consumeReservation(
                        $line['productId'],
                        $note['warehouseId'],
                        $qty,
                        $userId,
                        'Chargement '.$note['code'],
                        $note['code'],
                    );
                }
            }
            $note['loadedAt'] = $now;
            $note['loadedByUserId'] = $userId;
            $note['stockReserved'] = false;
            // Cycle préparation : ready/picking → loaded ; déjà affecté → assigned.
            if (in_array($status, ['ready', 'picking'], true) && empty($note['deliveryId']) && empty($note['shipmentId'])) {
                StatusTransition::assert('shipping_note', $status, 'loaded', 'Bon de livraison');
                $note['status'] = 'loaded';
            }
            if ($destinationWarehouseId) {
                $note['inTransitToWarehouseId'] = $destinationWarehouseId;
            }
            $this->notes->put($note);
            $this->notifier->audit('Bon chargé', 'shipping_note', $note['id'], $userId);

            return $note;
        });
    }

    /**
     * Crédite le stock destination lorsqu’une expédition inter-sites est terminée.
     */
    private function receiveTransferShipment(array $shipment, string $userId): array
    {
        $destinationWarehouseId = $shipment['destinationWarehouseId'] ?? null;
        if (! $destinationWarehouseId || ! empty($shipment['stockReceivedAt'])) {
            return $shipment;
        }

        $this->tenant->assertWarehouseAccess($destinationWarehouseId);
        foreach ($shipment['shippingNoteIds'] ?? [] as $noteId) {
            $note = $this->notes->find($noteId);
            if (! $note || empty($note['loadedAt'])) {
                continue;
            }
            if (! empty($note['stockReceivedAt'])) {
                continue;
            }
            foreach ($note['lines'] ?? [] as $line) {
                $this->stock->creditTransferInbound(
                    $line['productId'],
                    $destinationWarehouseId,
                    (int) $line['quantity'],
                    $userId,
                    'Réception transfert '.($note['code'] ?? $shipment['code'] ?? ''),
                    $note['warehouseId'] ?? ($shipment['originWarehouseId'] ?? null),
                    $note['code'] ?? ($shipment['code'] ?? null),
                );
            }
            $note['stockReceivedAt'] = now()->toIso8601String();
            $note['status'] = 'delivered';
            $this->notes->put($note);
        }

        $shipment['stockReceivedAt'] = now()->toIso8601String();
        $shipment['progress'] = 100;
        $this->notifier->audit('Transfert reçu', 'shipment', $shipment['id'], $userId);

        return $shipment;
    }

    private function assembleNote(array $input, ?array $existing = null): array
    {
        $warehouse = $this->warehouses->find($input['warehouseId'] ?? '');
        if (! $warehouse) {
            throw new DomainException('Entrepôt introuvable');
        }
        $customerId = $input['customerId'] ?? ($existing['customerId'] ?? null);
        $addressId = $input['addressId'] ?? ($existing['addressId'] ?? null);
        $customer = $customerId ? $this->customers->find($customerId) : null;
        $pickedAddress = null;
        if ($customer && ! empty($customer['addresses'])) {
            $pickedAddress = collect($customer['addresses'])->firstWhere('id', $addressId)
                ?? collect($customer['addresses'])->firstWhere('isDefault', true)
                ?? ($customer['addresses'][0] ?? null);
        }
        $client = trim($input['client'] ?? ($customer['name'] ?? ''));
        $address = trim($input['address'] ?? ($pickedAddress['address'] ?? ''));
        $city = trim(($input['city'] ?? '') ?: ($pickedAddress['city'] ?? ($warehouse['city'] ?? '')));
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
        $next = max(900, $this->notes->nextCodeSequence()) + 1;

        $carrierId = array_key_exists('carrierId', $input)
            ? ($input['carrierId'] ?: null)
            : ($existing['carrierId'] ?? null);
        $carrier = $carrierId ? \App\Support\Carriers::find((string) $carrierId) : null;
        $trackingNumber = array_key_exists('trackingNumber', $input)
            ? (trim((string) $input['trackingNumber']) ?: null)
            : ($existing['trackingNumber'] ?? null);

        return [
            'code' => $existing['code'] ?? 'BL-2026-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'warehouseId' => $warehouse['id'],
            'customerId' => $customer['id'] ?? $customerId,
            'addressId' => $pickedAddress['id'] ?? $addressId,
            'client' => $client,
            'address' => $address,
            'city' => $city,
            'lines' => $lines,
            'status' => $existing['status'] ?? 'ready',
            'deliveryId' => $existing['deliveryId'] ?? null,
            'shipmentId' => $existing['shipmentId'] ?? null,
            'notes' => trim((string) ($input['notes'] ?? $existing['notes'] ?? '')) ?: null,
            'carrierId' => $carrier['id'] ?? $carrierId,
            'carrierName' => $carrier['name'] ?? ($existing['carrierName'] ?? null),
            'trackingNumber' => $trackingNumber,
            'labelPrintedAt' => $existing['labelPrintedAt'] ?? null,
            'bookedAt' => $existing['bookedAt'] ?? null,
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
                'dueAt' => now()->setTime(17, 0)->toIso8601String(),
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
                $this->releaseNoteReservation($note);
                $note['shipmentId'] = null;
                if (($note['status'] ?? '') === 'assigned' && empty($note['deliveryId'])) {
                    $note['status'] = 'ready';
                }
                $this->notes->put($note);
            }
            if (in_array($note['id'], $ids, true)) {
                $note['shipmentId'] = $shipment['id'];
                if (in_array($note['status'] ?? '', ['ready', 'picking', 'loaded', 'assigned'], true)) {
                    $note['status'] = ($shipment['status'] ?? '') === 'done' ? 'delivered' : 'assigned';
                    if (($shipment['status'] ?? '') !== 'done') {
                        $this->reserveNoteStock($note);
                    }
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

    /**
     * Crée / met à jour / retire les arrêts d'une tournée à partir des stops du formulaire.
     *
     * @param  list<array<string, mixed>>  $stops
     */
    private function syncRouteStops(array $route, array $stops): void
    {
        $warehouseId = $route['warehouseId'] ?? '';
        $existing = $this->deliveries->forWarehouses([$warehouseId])
            ->filter(fn (array $item) => ($item['routeId'] ?? null) === $route['id'])
            ->keyBy('id');

        $keep = [];
        foreach ($stops as $stop) {
            if (! empty($stop['id'])) {
                $keep[] = (string) $stop['id'];
            }
        }

        foreach ($existing as $delivery) {
            if (! in_array($delivery['id'], $keep, true)) {
                $this->deliveryService->remove($delivery['id']);
            }
        }

        foreach (array_values($stops) as $index => $stop) {
            if (empty($stop['shippingNoteId']) && empty($stop['client'])) {
                throw new DomainException('Chaque arrêt doit avoir un bon de livraison.');
            }
            $payload = [
                'shippingNoteId' => $stop['shippingNoteId'] ?? '',
                'client' => $stop['client'] ?? null,
                'address' => $stop['address'] ?? null,
                'city' => $stop['city'] ?? null,
                'scheduledAt' => $stop['scheduledAt'] ?? ($route['date'].'T09:00:00.000Z'),
                'routeId' => $route['id'],
                'status' => $stop['status'] ?? 'prepared',
                'sequence' => $index + 1,
                'notes' => $stop['notes'] ?? null,
                'lat' => $stop['lat'] ?? null,
                'lng' => $stop['lng'] ?? null,
            ];
            $stopId = $stop['id'] ?? null;
            if ($stopId && $existing->has($stopId)) {
                $this->deliveryService->update((string) $stopId, $payload);
            } else {
                $this->deliveryService->create($payload);
            }
        }
    }

    private function relinkRouteDeliveries(array $route): void
    {
        $stops = $this->deliveries->forWarehouses([$route['warehouseId'] ?? ''])
            ->filter(fn (array $item) => ($item['routeId'] ?? null) === $route['id']);

        foreach ($stops as $stop) {
            if (($stop['status'] ?? '') === 'delivered') {
                continue;
            }
            $this->deliveries->merge($stop['id'], [
                'driverId' => $route['driverId'] ?? $stop['driverId'],
                'vehicleId' => $route['vehicleId'] ?? $stop['vehicleId'],
                'warehouseId' => $route['warehouseId'] ?? $stop['warehouseId'],
            ], 'Livraison introuvable');
        }
    }

    /** Recalcule géométrie / progress d'une tournée et propage vers les expéditions. */
    public function refreshRouteTracking(string $routeId): array
    {
        $route = $this->routes->findOrFail($routeId, 'Tournée introuvable');
        $this->tenant->assertWarehouseAccess($route['warehouseId'] ?? '');

        return $this->refreshRouteGeometry($route);
    }

    private function refreshRouteGeometry(array $route): array
    {
        $warehouse = $this->warehouses->find($route['warehouseId'] ?? '');
        $origin = [
            'lat' => (float) ($warehouse['lat'] ?? 33.5731),
            'lng' => (float) ($warehouse['lng'] ?? -7.5898),
            'label' => 'Entrepôt '.($warehouse['name'] ?? ''),
        ];
        $stops = $this->deliveries->forWarehouses([$route['warehouseId'] ?? ''])
            ->filter(fn (array $item) => ($item['routeId'] ?? null) === $route['id'])
            ->sortBy(fn (array $item) => $item['sequence'] ?? 0)
            ->values();

        $path = [$origin];
        foreach ($stops as $stop) {
            if (! empty($stop['location']['lat']) && ! empty($stop['location']['lng'])) {
                $path[] = [
                    'lat' => (float) $stop['location']['lat'],
                    'lng' => (float) $stop['location']['lng'],
                    'label' => $stop['client'] ?? null,
                ];
            }
        }

        $delivered = $stops->where('status', 'delivered')->count();
        $active = $stops->first(
            fn (array $item) => in_array($item['status'] ?? '', ['in_transit', 'at_risk'], true),
        );
        $status = $route['status'] ?? 'planned';
        if ($stops->isNotEmpty() && $delivered === $stops->count()) {
            $status = 'done';
        } elseif ($active || $delivered > 0) {
            $status = $status === 'planned' ? 'in_progress' : $status;
        }

        $progress = 0;
        if ($stops->isNotEmpty()) {
            $seq = (int) ($active['sequence'] ?? $delivered);
            $partial = $active ? 1 - (((int) ($active['progress'] ?? 0)) / 100) : 0;
            $progress = (int) min(100, max(0, round((($seq - $partial) / $stops->count()) * 100)));
        }

        $current = $active['currentPosition']
            ?? ($path ? Geo::pointAlong($path, min(1, max(0, $progress / 100))) : $origin);

        if ($status !== ($route['status'] ?? 'planned')) {
            StatusTransition::assert('route', (string) ($route['status'] ?? 'planned'), $status, 'Tournée');
        }

        $route = $this->routes->merge($route['id'], [
            'status' => $status,
            'distanceKm' => max(1, (int) round(Geo::pathLengthKm($path))),
            'path' => $path,
            'currentPosition' => $current,
            'progress' => $progress,
        ], 'Tournée introuvable');

        $this->syncShipmentsForRoute($route);

        return $route;
    }

    /**
     * Propage progress / position / ETA de la tournée vers les expéditions liées.
     */
    private function syncShipmentsForRoute(array $route): void
    {
        foreach ($this->shipments->all() as $shipment) {
            if (($shipment['routeId'] ?? null) !== $route['id']) {
                continue;
            }
            $patched = $this->applyRouteTrackingToShipment($shipment, $route);
            $this->shipments->put($patched);
        }
    }

    /** @param  array<string, mixed>  $shipment */
    private function applyRouteTrackingToShipment(array $shipment, ?array $route = null): array
    {
        $route ??= $this->routes->find($shipment['routeId'] ?? '');
        if (! $route) {
            return $shipment;
        }
        $shipment['progress'] = (int) ($route['progress'] ?? $shipment['progress'] ?? 0);
        if (! empty($route['currentPosition'])) {
            $shipment['currentPosition'] = $route['currentPosition'];
        }
        if (! empty($route['path'])) {
            $shipment['path'] = $route['path'];
        }
        if (! empty($route['eta']) || ! empty($route['etaAt'])) {
            $shipment['eta'] = $route['eta'] ?? $route['etaAt'];
        }
        $routeStatus = $route['status'] ?? '';
        $shipmentStatus = $shipment['status'] ?? 'preparing';
        if ($routeStatus === 'done' && $shipmentStatus !== 'done') {
            // Ne force pas done ici (stock inbound) — seulement aligner le transit.
            if ($shipmentStatus === 'preparing') {
                $shipment['status'] = 'in_transit';
            }
        } elseif ($routeStatus === 'in_progress' && $shipmentStatus === 'preparing') {
            $shipment['status'] = 'in_transit';
        }

        return $shipment;
    }

    private function reserveNoteStock(array &$note): void
    {
        if (! empty($note['stockReserved']) || ! empty($note['loadedAt'])) {
            return;
        }
        foreach ($note['lines'] ?? [] as $line) {
            $this->stock->reserve($line['productId'], $note['warehouseId'], (int) $line['quantity']);
        }
        $note['stockReserved'] = true;
    }

    private function releaseNoteReservation(array &$note): void
    {
        if (empty($note['stockReserved']) || ! empty($note['loadedAt'])) {
            return;
        }
        foreach ($note['lines'] ?? [] as $line) {
            $this->stock->release($line['productId'], $note['warehouseId'], (int) $line['quantity']);
        }
        $note['stockReserved'] = false;
    }
}
