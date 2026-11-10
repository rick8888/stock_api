<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\DeliveryRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\RouteRepository;
use App\Repositories\Eloquent\ShipmentRepository;
use App\Repositories\Eloquent\ShippingNoteRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\DemoDate;
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
            $delivery['status'] = $status;
            if ($note) {
                $delivery['notes'] = $note;
            }
            if ($status === 'delivered') {
                $now = now()->toIso8601String();
                $delivery['progress'] = 100;
                $delivery['signedAt'] = $proof['signedAt'] ?? $now;
                $delivery['signedBy'] = $signedBy ?: ($delivery['signedBy'] ?? 'Destinataire');
                if (! empty($proof['signatureDataUrl'])) {
                    $delivery['signatureDataUrl'] = $proof['signatureDataUrl'];
                }
                if (isset($proof['lat'], $proof['lng'])) {
                    $delivery['podLocation'] = [
                        'lat' => (float) $proof['lat'],
                        'lng' => (float) $proof['lng'],
                        'at' => $delivery['signedAt'],
                    ];
                }
                $linked = $this->notes->all()->first(
                    fn (array $item) => $item['id'] === ($delivery['shippingNoteId'] ?? null) || ($item['deliveryId'] ?? null) === $delivery['id'],
                );
                if ($linked) {
                    $linked['status'] = 'delivered';
                    $this->notes->put($linked);
                }
                if (! empty($delivery['location'])) {
                    $delivery['currentPosition'] = [...$delivery['location'], 'at' => $now];
                }
                $this->notifier->notify('Livraison effectuée', ($delivery['client'] ?? '').' — '.($delivery['city'] ?? ''));
            }
            if ($status === 'failed') {
                $linked = $this->notes->all()->first(
                    fn (array $item) => $item['id'] === ($delivery['shippingNoteId'] ?? null) || ($item['deliveryId'] ?? null) === $delivery['id'],
                );
                if ($linked && ($linked['status'] ?? '') !== 'delivered') {
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
                        $linked['stockReserved'] = false;
                    } else {
                        $this->releaseNoteReservation($linked);
                    }
                    $this->notes->put($linked);
                }
            }
            $this->deliveries->put($delivery);

            return $delivery;
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
        $point = [
            'lat' => $warehouse['lat'] ?? 33.5731,
            'lng' => $warehouse['lng'] ?? -7.5898,
            'label' => $input['city'] ?? ($warehouse['city'] ?? 'Stop'),
        ];
        $seq = $this->deliveries->countAll() + 1;

        return [
            'client' => $input['client'] ?? ($note['client'] ?? ''),
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
            'path' => $existing['path'] ?? [$point],
            'currentPosition' => $existing['currentPosition'] ?? $point,
            'progress' => $existing['progress'] ?? 0,
            'lines' => $lines,
            'shippingNoteId' => $input['shippingNoteId'] ?? ($existing['shippingNoteId'] ?? null),
        ];
    }

    private function bindNote(array $delivery): void
    {
        foreach ($this->notes->all() as $note) {
            if (($note['deliveryId'] ?? null) === $delivery['id'] && $note['id'] !== ($delivery['shippingNoteId'] ?? null)) {
                $this->releaseNoteReservation($note);
                $note['deliveryId'] = null;
                if (($note['status'] ?? '') === 'assigned' && empty($note['shipmentId'])) {
                    $note['status'] = 'ready';
                }
                $this->notes->put($note);
            }
        }
        $note = $this->notes->find($delivery['shippingNoteId'] ?? '');
        if (! $note) {
            return;
        }
        $note['deliveryId'] = $delivery['id'];
        $note['status'] = ($delivery['status'] ?? '') === 'delivered' ? 'delivered' : 'assigned';
        if (($delivery['status'] ?? '') !== 'delivered') {
            $this->reserveNoteStock($note);
        }
        $this->notes->put($note);
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
