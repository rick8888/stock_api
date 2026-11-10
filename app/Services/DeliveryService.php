<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\ShipmentRepository;
use App\Repositories\Eloquent\ShippingNoteRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\DemoDate;
use App\Support\StatusTransition;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryService
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly RouteRepository $routes,
        private readonly WarehouseRepository $warehouses,
        private readonly ProductRepository $products,
        private readonly ShippingNoteRepository $notes,
        private readonly ShipmentRepository $shipments,
        private readonly StockItemRepository $stockItems,
        private readonly StockService $stock,
        private readonly OpsNotifier $notifier,
        private readonly Tenant $tenant,
        private readonly CustomerRepository $customers,
    ) {}

    public function list(): Collection
    {
        $items = $this->tenant->bypassTenantFilter()
            ? $this->deliveries->all()
            : $this->deliveries->forWarehouses($this->tenant->companyWarehouseIds());

        return $items
            ->map(function (array $delivery) {
                if (! empty($delivery['scheduledAt'])) {
                    $delivery['scheduledAt'] = DemoDate::rebase($delivery['scheduledAt']);
                }
                if (! empty($delivery['eta'])) {
                    $delivery['eta'] = DemoDate::rebase($delivery['eta']);
                }

                return $delivery;
            })
            ->values();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $delivery = $this->deliveries->put([
                'id' => Uid::make('dlv'),
                ...$this->assemble($input),
            ]);
            $this->bindNote($delivery);

            return $delivery;
        });
    }

    public function update(string $id, array $input): array
    {
        return DB::transaction(function () use ($id, $input) {
            $delivery = $this->deliveries->findOrFail($id, 'Livraison introuvable');
            $merged = [
                'shippingNoteId' => $input['shippingNoteId'] ?? $delivery['shippingNoteId'] ?? '',
                'client' => $input['client'] ?? $delivery['client'],
                'address' => $input['address'] ?? $delivery['address'],
                'city' => $input['city'] ?? $delivery['city'],
                'scheduledAt' => $input['scheduledAt'] ?? $delivery['scheduledAt'],
                'routeId' => $input['routeId'] ?? $delivery['routeId'],
                'notes' => $input['notes'] ?? ($delivery['notes'] ?? null),
                'lines' => $input['lines'] ?? collect($delivery['lines'] ?? [])->map(fn (array $line) => [
                    'productId' => $line['productId'],
                    'quantity' => $line['quantity'],
                ])->all(),
                'sequence' => $input['sequence'] ?? $delivery['sequence'] ?? 1,
                'status' => $input['status'] ?? $delivery['status'],
            ];
            $delivery = $this->deliveries->put(['id' => $id, ...$this->assemble($merged, $delivery)]);
            $this->bindNote($delivery);

            return $delivery;
        });
    }

    public function updateStatus(
        string $id,
        string $status,
        ?string $note = null,
        ?string $signedBy = null,
        ?array $proof = null,
    ): array {
        return DB::transaction(function () use ($id, $status, $note, $signedBy, $proof) {
            $delivery = $this->deliveries->findOrFail($id, 'Livraison introuvable');
            $linked = $this->notes->all()->first(
                fn (array $item) => $item['id'] === ($delivery['shippingNoteId'] ?? null) || ($item['deliveryId'] ?? null) === $delivery['id'],
            );

            StatusTransition::assert('delivery', (string) ($delivery['status'] ?? 'prepared'), $status, 'Livraison');

            if (in_array($status, ['in_transit', 'at_risk', 'delivered'], true) && $linked) {
                $this->ensureNoteLoaded($linked, $delivery);
            }

            $delivery['status'] = $status;
            if ($note) {
                $delivery['notes'] = $note;
            }
            if ($status === 'delivered') {
                $hasSignature = ! empty($proof['signatureDataUrl']);
                $hasCoords = isset($proof['lat'], $proof['lng']);
                if (! $hasSignature && ! $hasCoords) {
                    throw new DomainException(
                        'Preuve de livraison requise : signature ou coordonnées GPS.',
                    );
                }
                $now = now()->toIso8601String();
                $delivery['progress'] = 100;
                $delivery['signedAt'] = $proof['signedAt'] ?? $now;
                $delivery['signedBy'] = $signedBy ?: ($delivery['signedBy'] ?? 'Destinataire');
                if ($hasSignature) {
                    $delivery['signatureDataUrl'] = $proof['signatureDataUrl'];
                }
                if ($hasCoords) {
                    $delivery['podLocation'] = [
                        'lat' => (float) $proof['lat'],
                        'lng' => (float) $proof['lng'],
                        'at' => $delivery['signedAt'],
                    ];
                }
                if ($linked) {
                    $linked = $this->notes->find($linked['id']) ?? $linked;
                    StatusTransition::assert(
                        'shipping_note',
                        (string) ($linked['status'] ?? 'assigned'),
                        'delivered',
                        'Bon de livraison',
                    );
                    $linked['status'] = 'delivered';
                    $this->notes->put($linked);
                }
                if (! empty($delivery['location'])) {
                    $delivery['currentPosition'] = [...$delivery['location'], 'at' => $now];
                }
                $this->notifier->notify('Livraison effectuée', ($delivery['client'] ?? '').' — '.($delivery['city'] ?? ''));
            }
            if ($status === 'failed') {
                if ($linked && ($linked['status'] ?? '') !== 'delivered') {
                    $linked = $this->notes->find($linked['id']) ?? $linked;
                    if (! empty($linked['loadedAt'])) {
                        foreach ($linked['lines'] ?? [] as $line) {
                            $item = $this->stock->ensureLine($line['productId'], $linked['warehouseId']);
                            $item['quantity'] = (int) $item['quantity'] + (int) $line['quantity'];
                            $this->stock->putLine($item);
                            $this->stock->move(
                                'return',
                                $line['productId'],
                                (int) $line['quantity'],
                                $this->tenant->actorId(),
                                'Retour échec '.($linked['code'] ?? ''),
                                null,
                                $linked['warehouseId'],
                                $delivery['id'],
                            );
                        }
                        $linked['loadedAt'] = null;
                        $linked['loadedByUserId'] = null;
                        $linked['stockReserved'] = false;
                    } else {
                        $this->releaseNoteReservation($linked);
                    }
                    // Remet le BL disponible pour replanification.
                    StatusTransition::assert(
                        'shipping_note',
                        (string) ($linked['status'] ?? 'assigned'),
                        'ready',
                        'Bon de livraison',
                    );
                    $linked['deliveryId'] = null;
                    $linked['status'] = 'ready';
                    $this->notes->put($linked);
                    $delivery['shippingNoteId'] = null;
                }
            }
            $this->deliveries->put($delivery);

            if (! empty($delivery['routeId'])) {
                // Évite la dépendance circulaire constructeur LogisticsService ↔ DeliveryService.
                app(LogisticsService::class)->refreshRouteTracking($delivery['routeId']);
            }

            return $this->deliveries->find($delivery['id']) ?? $delivery;
        });
    }

    /**
     * Retire un arrêt de tournée et libère le BL associé.
     */
    public function remove(string $id): void
    {
        DB::transaction(function () use ($id) {
            $delivery = $this->deliveries->findOrFail($id, 'Livraison introuvable');
            if (($delivery['status'] ?? '') === 'delivered') {
                throw new DomainException('Impossible de retirer une livraison déjà effectuée.');
            }
            $note = $this->notes->find($delivery['shippingNoteId'] ?? '');
            if ($note) {
                if (! empty($note['loadedAt'])) {
                    throw new DomainException('Retirez d’abord le chargement du bon '.$note['code'].' avant de l’enlever de la tournée.');
                }
                $this->releaseNoteReservation($note);
                $note['deliveryId'] = null;
                if (($note['status'] ?? '') === 'assigned' && empty($note['shipmentId'])) {
                    $note['status'] = ! empty($note['loadedAt']) ? 'loaded' : 'ready';
                }
                $this->notes->put($note);
            }
            $this->deliveries->delete($id);
        });
    }

    private function assemble(array $input, ?array $existing = null): array
    {
        $route = $this->routes->find($input['routeId'] ?? '');
        if (! $route) {
            throw new DomainException('Tournée introuvable');
        }
        $warehouse = $this->warehouses->find($route['warehouseId'] ?? '');
        $note = $this->notes->find($input['shippingNoteId'] ?? '');
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
        if (! $lines && $note) {
            $lines = $note['lines'] ?? [];
        }
        $client = $input['client'] ?? ($note['client'] ?? '');
        $point = $this->resolveStopPoint($input, $note, $warehouse, $client);
        $seq = $this->deliveries->countAll() + 1;
        $origin = [
            'lat' => (float) ($warehouse['lat'] ?? 33.5731),
            'lng' => (float) ($warehouse['lng'] ?? -7.5898),
            'label' => 'Entrepôt '.($warehouse['name'] ?? ''),
        ];

        return [
            'client' => $client,
            'address' => $input['address'] ?? ($note['address'] ?? ''),
            'city' => $input['city'] ?? ($note['city'] ?? ''),
            'scheduledAt' => $input['scheduledAt'],
            'status' => $input['status'] ?? 'prepared',
            'driverId' => $route['driverId'],
            'vehicleId' => $route['vehicleId'],
            'routeId' => $route['id'],
            'sequence' => $input['sequence'] ?? ($existing['sequence'] ?? $seq),
            'notes' => $input['notes'] ?? null,
            'code' => $existing['code'] ?? 'BL-2026-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'warehouseId' => $route['warehouseId'],
            'location' => $existing['location'] ?? $point,
            'path' => $existing['path'] ?? [$origin, $point],
            'currentPosition' => $existing['currentPosition'] ?? $origin,
            'progress' => $existing['progress'] ?? 0,
            'lines' => $lines,
            'shippingNoteId' => $input['shippingNoteId'] ?? ($existing['shippingNoteId'] ?? null),
        ];
    }

    /**
     * Priorité des coords : saisie explicite → adresse client → entrepôt.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $note
     * @param  array<string, mixed>|null  $warehouse
     * @return array{lat: float, lng: float, label: string}
     */
    private function resolveStopPoint(array $input, ?array $note, ?array $warehouse, string $label): array
    {
        if (isset($input['lat'], $input['lng'])) {
            return ['lat' => (float) $input['lat'], 'lng' => (float) $input['lng'], 'label' => $label];
        }

        if ($note) {
            $customerId = $note['customerId'] ?? null;
            $addressId = $note['addressId'] ?? null;
            if ($customerId) {
                $customer = $this->customers->find((string) $customerId);
                foreach ($customer['addresses'] ?? [] as $address) {
                    if ($addressId && ($address['id'] ?? null) !== $addressId) {
                        continue;
                    }
                    if (isset($address['lat'], $address['lng'])) {
                        return [
                            'lat' => (float) $address['lat'],
                            'lng' => (float) $address['lng'],
                            'label' => $label !== '' ? $label : (string) ($address['label'] ?? 'Stop'),
                        ];
                    }
                    if ($addressId) {
                        break;
                    }
                }
            }
        }

        return [
            'lat' => (float) ($warehouse['lat'] ?? 33.5731),
            'lng' => (float) ($warehouse['lng'] ?? -7.5898),
            'label' => $label !== '' ? $label : (string) ($warehouse['city'] ?? 'Stop'),
        ];
    }

    private function bindNote(array $delivery): void
    {
        foreach ($this->notes->all() as $note) {
            if (($note['deliveryId'] ?? null) === $delivery['id'] && $note['id'] !== ($delivery['shippingNoteId'] ?? null)) {
                $this->releaseNoteReservation($note);
                $note['deliveryId'] = null;
                if (($note['status'] ?? '') === 'assigned' && empty($note['shipmentId'])) {
                    $note['status'] = ! empty($note['loadedAt']) ? 'loaded' : 'ready';
                }
                $this->notes->put($note);
            }
        }
        $note = $this->notes->find($delivery['shippingNoteId'] ?? '');
        if (! $note) {
            return;
        }
        if (($note['deliveryId'] ?? null) && $note['deliveryId'] !== $delivery['id']) {
            throw new DomainException('Ce bon de livraison est déjà affecté à un autre arrêt.');
        }
        $note['deliveryId'] = $delivery['id'];
        $note['status'] = ($delivery['status'] ?? '') === 'delivered' ? 'delivered' : 'assigned';
        if (($delivery['status'] ?? '') !== 'delivered') {
            $this->reserveNoteStock($note);
        }
        $this->notes->put($note);
    }

    /**
     * Garantit que le stock du BL a bien été sorti avant départ / POD.
     * Si réservé mais pas chargé → consommation auto. Sinon erreur.
     *
     * @param  array<string, mixed>  $note
     * @param  array<string, mixed>  $delivery
     */
    private function ensureNoteLoaded(array &$note, array $delivery): void
    {
        if (! ($note['lines'] ?? [])) {
            return;
        }
        if (! empty($note['loadedAt'])) {
            return;
        }

        if (! empty($note['stockReserved'])) {
            foreach ($note['lines'] as $line) {
                $this->stock->consumeReservation(
                    $line['productId'],
                    $note['warehouseId'],
                    (int) $line['quantity'],
                    $this->tenant->actorId(),
                    'Chargement auto POD '.($note['code'] ?? ''),
                    $note['code'] ?? $delivery['id'],
                );
            }
            $note['loadedAt'] = now()->toIso8601String();
            $note['loadedByUserId'] = $this->tenant->actorId();
            $note['stockReserved'] = false;
            $this->notes->put($note);

            return;
        }

        throw new DomainException(
            'Le bon '.($note['code'] ?? '').' doit être chargé avant le départ ou la preuve de livraison.',
        );
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
