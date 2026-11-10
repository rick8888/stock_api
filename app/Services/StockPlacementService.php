<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\StockPlacementRepository;
use App\Repositories\Eloquent\WarehouseLocationRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;

class StockPlacementService
{
    public function __construct(
        private readonly StockPlacementRepository $placements,
        private readonly WarehouseLocationRepository $locations,
        private readonly Tenant $tenant,
    ) {}

    public function list(?string $warehouseId = null, ?string $productId = null): Collection
    {
        if ($warehouseId) {
            $this->tenant->assertWarehouseAccess($warehouseId);

            return $this->placements->forWarehouse($warehouseId, $productId);
        }

        $items = $this->tenant->filterByWarehouseKeys($this->placements->all(), ['warehouseId']);
        if ($productId) {
            $items = $items->filter(fn (array $row) => ($row['productId'] ?? '') === $productId);
        }

        return $items->values();
    }

    public function putaway(
        string $productId,
        string $warehouseId,
        int $quantity,
        ?string $locationId = null,
        ?string $lotId = null,
    ): ?array {
        if ($quantity <= 0) {
            throw new DomainException('Quantité invalide');
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $location = $this->resolveLocation($warehouseId, $locationId, $productId);
        if (! $location) {
            return null;
        }
        $existing = $this->placements->findLine($productId, $warehouseId, $location['id'], $lotId);
        if ($existing) {
            $existing['quantity'] = (int) $existing['quantity'] + $quantity;

            return $this->placements->put($existing);
        }

        return $this->placements->put($this->tenant->stamp([
            'id' => Uid::make('plc'),
            'productId' => $productId,
            'warehouseId' => $warehouseId,
            'locationId' => $location['id'],
            'lotId' => $lotId,
            'quantity' => $quantity,
        ]));
    }

    /**
     * Consomme le stock emplacement en chemin de picking (code croissant).
     *
     * @return list<array{locationId: string, locationCode: string, quantity: int, lotId?: string|null}>
     */
    public function consumePath(string $productId, string $warehouseId, int $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $locations = $this->locations->forWarehouse($warehouseId)->keyBy('id');
        $rows = $this->list($warehouseId, $productId)
            ->filter(fn (array $row) => (int) ($row['quantity'] ?? 0) > 0)
            ->sortBy(function (array $row) use ($locations) {
                $loc = $locations->get($row['locationId'] ?? '');

                return $loc['code'] ?? 'ZZZ';
            })
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $remaining = $quantity;
        $touched = [];
        foreach ($rows as $row) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $row['quantity']);
            $row['quantity'] = (int) $row['quantity'] - $take;
            $this->placements->put($row);
            $loc = $locations->get($row['locationId'] ?? '');
            $touched[] = [
                'locationId' => $row['locationId'],
                'locationCode' => $loc['code'] ?? $row['locationId'],
                'quantity' => $take,
                'lotId' => $row['lotId'] ?? null,
            ];
            $remaining -= $take;
        }

        return $touched;
    }

    /**
     * Chemin de picking pour une liste de lignes.
     *
     * @param  list<array{productId: string, quantity: int, productName?: string, sku?: string}>  $lines
     * @return list<array<string, mixed>>
     */
    public function pickPath(string $warehouseId, array $lines): array
    {
        $this->tenant->assertWarehouseAccess($warehouseId);
        $locations = $this->locations->forWarehouse($warehouseId)
            ->filter(fn (array $row) => ($row['active'] ?? true))
            ->keyBy('id');
        $steps = [];
        foreach ($lines as $line) {
            $productId = $line['productId'] ?? '';
            $need = (int) ($line['quantity'] ?? 0);
            if ($productId === '' || $need <= 0) {
                continue;
            }
            $rows = $this->list($warehouseId, $productId)
                ->filter(fn (array $row) => (int) ($row['quantity'] ?? 0) > 0)
                ->sortBy(function (array $row) use ($locations) {
                    $loc = $locations->get($row['locationId'] ?? '');

                    return $loc['code'] ?? 'ZZZ';
                })
                ->values();
            $left = $need;
            if ($rows->isEmpty()) {
                $steps[] = [
                    'productId' => $productId,
                    'productName' => $line['productName'] ?? $productId,
                    'sku' => $line['sku'] ?? null,
                    'quantity' => $need,
                    'locationId' => null,
                    'locationCode' => null,
                    'aisle' => null,
                    'rack' => null,
                    'bin' => null,
                    'unlocated' => true,
                ];
                continue;
            }
            foreach ($rows as $row) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (int) $row['quantity']);
                $loc = $locations->get($row['locationId'] ?? '');
                $steps[] = [
                    'productId' => $productId,
                    'productName' => $line['productName'] ?? $productId,
                    'sku' => $line['sku'] ?? null,
                    'quantity' => $take,
                    'locationId' => $row['locationId'],
                    'locationCode' => $loc['code'] ?? null,
                    'aisle' => $loc['aisle'] ?? null,
                    'rack' => $loc['rack'] ?? null,
                    'bin' => $loc['bin'] ?? null,
                    'unlocated' => false,
                ];
                $left -= $take;
            }
            if ($left > 0) {
                $steps[] = [
                    'productId' => $productId,
                    'productName' => $line['productName'] ?? $productId,
                    'sku' => $line['sku'] ?? null,
                    'quantity' => $left,
                    'locationId' => null,
                    'locationCode' => null,
                    'aisle' => null,
                    'rack' => null,
                    'bin' => null,
                    'unlocated' => true,
                ];
            }
        }

        return collect($steps)
            ->sortBy(fn (array $step) => ($step['locationCode'] ?? 'ZZZ').'|'.($step['productId'] ?? ''))
            ->values()
            ->all();
    }

    public function suggestPutaway(string $warehouseId, string $productId): ?array
    {
        $this->tenant->assertWarehouseAccess($warehouseId);
        $existing = $this->list($warehouseId, $productId)
            ->filter(fn (array $row) => (int) ($row['quantity'] ?? 0) > 0)
            ->first();
        if ($existing) {
            return $this->locations->find($existing['locationId'] ?? '');
        }
        $candidates = $this->locations->forWarehouse($warehouseId)
            ->filter(fn (array $row) => ($row['active'] ?? true))
            ->sortBy(fn (array $row) => (($row['type'] ?? '') === 'pick' ? '0' : '1').($row['code'] ?? ''))
            ->values();

        return $candidates->first();
    }

    private function resolveLocation(string $warehouseId, ?string $locationId, string $productId): ?array
    {
        if ($locationId) {
            $location = $this->locations->find($locationId);
            if (! $location || ($location['warehouseId'] ?? '') !== $warehouseId) {
                throw new DomainException('Emplacement introuvable.');
            }
            if (! ($location['active'] ?? true)) {
                throw new DomainException('Emplacement inactif.');
            }

            return $location;
        }

        return $this->suggestPutaway($warehouseId, $productId);
    }
}
