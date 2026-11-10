<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\StockLotRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;

class StockLotService
{
    public function __construct(
        private readonly StockLotRepository $lots,
        private readonly Tenant $tenant,
    ) {}

    public function list(?string $warehouseId = null, ?string $productId = null): Collection
    {
        if ($warehouseId) {
            $this->tenant->assertWarehouseAccess($warehouseId);

            return $this->lots->forWarehouse($warehouseId, $productId);
        }

        $items = $this->tenant->filterByWarehouseKeys($this->lots->all(), ['warehouseId']);
        if ($productId) {
            $items = $items->filter(fn (array $row) => ($row['productId'] ?? '') === $productId);
        }

        return $items->sortBy(fn (array $row) => $row['expiryDate'] ?? '9999-12-31')->values();
    }

    public function receive(
        string $productId,
        string $warehouseId,
        int $quantity,
        string $lotCode,
        ?string $expiryDate = null,
        ?string $locationId = null,
    ): array {
        if ($quantity <= 0) {
            throw new DomainException('Quantité invalide');
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $code = trim($lotCode) ?: ('LOT-'.strtoupper(substr(Uid::make('x'), -6)));
        $existing = $this->lots->findByCode($productId, $warehouseId, $code);
        if ($existing) {
            $existing['quantity'] = (int) $existing['quantity'] + $quantity;
            if ($expiryDate) {
                $existing['expiryDate'] = $expiryDate;
            }
            if ($locationId) {
                $existing['locationId'] = $locationId;
            }

            return $this->lots->put($existing);
        }

        return $this->lots->put($this->tenant->stamp([
            'id' => Uid::make('lot'),
            'productId' => $productId,
            'warehouseId' => $warehouseId,
            'lotCode' => $code,
            'expiryDate' => $expiryDate,
            'locationId' => $locationId,
            'quantity' => $quantity,
        ]));
    }

    /**
     * Consomme en FEFO (DLC croissante). Retourne les lots touchés.
     *
     * @return list<array{lotId: string, lotCode: string, quantity: int}>
     */
    public function consumeFefo(string $productId, string $warehouseId, int $quantity): array
    {
        if ($quantity <= 0) {
            return [];
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $lots = $this->list($warehouseId, $productId)
            ->filter(fn (array $row) => (int) ($row['quantity'] ?? 0) > 0)
            ->values();
        $remaining = $quantity;
        $touched = [];
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (int) $lot['quantity']);
            $lot['quantity'] = (int) $lot['quantity'] - $take;
            $this->lots->put($lot);
            $touched[] = [
                'lotId' => $lot['id'],
                'lotCode' => $lot['lotCode'] ?? $lot['id'],
                'quantity' => $take,
                'expiryDate' => $lot['expiryDate'] ?? null,
            ];
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new DomainException('Lots insuffisants (FEFO) pour cette sortie.');
        }

        return $touched;
    }
}
