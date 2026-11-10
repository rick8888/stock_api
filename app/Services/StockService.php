<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\InventorySessionRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\StockMovementRepository;
use App\Repositories\Eloquent\WarehouseLocationRepository;
use App\Support\StockDocuments;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(
        private readonly StockItemRepository $stock,
        private readonly StockMovementRepository $movements,
        private readonly InventorySessionRepository $inventories,
        private readonly ProductRepository $products,
        private readonly OpsNotifier $notifier,
        private readonly UserDirectory $users,
        private readonly Tenant $tenant,
        private readonly StockLotService $lots,
        private readonly StockPlacementService $placements,
        private readonly WarehouseLocationRepository $locations,
    ) {}

    public function list(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            $ids = $this->tenant->companyWarehouseIds();
            $scoped = $this->stock->forWarehouses($ids);
            $userScope = $this->tenant->user()?->warehouse_ids ?? [];
            if ($userScope) {
                $allowed = array_flip($userScope);

                return $scoped->filter(fn (array $row) => isset($allowed[$row['warehouseId'] ?? '']))->values();
            }

            return $scoped;
        }

        return $this->tenant->filterByWarehouseKeys($this->stock->all(), ['warehouseId']);
    }

    public function movements(): Collection
    {
        return $this->tenant->filterByWarehouseKeys($this->movements->all());
    }

    public function inventories(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->inventories->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->inventories->all();
    }

    public function receive(string $productId, string $warehouseId, int $quantity, string $userId, string $note, ?string $reference): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $note, $reference) {
            if ($quantity <= 0) {
                throw new DomainException('Quantité invalide');
            }
            $this->tenant->assertWarehouseAccess($warehouseId);
            $piece = StockDocuments::assertInbound($reference, $note);
            $item = $this->ensureLine($productId, $warehouseId);
            $item['quantity'] = (int) $item['quantity'] + $quantity;
            $this->stock->put($item);
            $this->move('receive', $productId, $quantity, $userId, $note, null, $warehouseId, $piece);
            $this->notifier->audit('Réception stock', 'stock', $productId, $userId);
        });
    }

    public function issue(string $productId, string $warehouseId, int $quantity, string $userId, string $note, ?string $reference): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $note, $reference) {
            if ($quantity <= 0) {
                throw new DomainException('Quantité invalide');
            }
            $this->tenant->assertWarehouseAccess($warehouseId);
            $piece = StockDocuments::assertOutbound($reference, $note);
            $item = $this->lineForUpdate($productId, $warehouseId);
            if ($this->available($item) < $quantity) {
                throw new DomainException('Stock insuffisant');
            }
            $this->consumeLotsIfTracked($productId, $warehouseId, $quantity);
            $item['quantity'] = (int) $item['quantity'] - $quantity;
            $this->stock->put($item);
            $this->move('issue', $productId, $quantity, $userId, $note, $warehouseId, null, $piece);
            $this->notifier->audit('Sortie de stock', 'stock', $productId, $userId);
        });
    }

    public function transfer(string $productId, string $fromWarehouseId, string $toWarehouseId, int $quantity, string $userId, string $note = 'Transfert'): void
    {
        DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $userId, $note) {
            if ($fromWarehouseId === $toWarehouseId) {
                throw new DomainException('Choisissez un autre entrepôt.');
            }
            if ($quantity <= 0) {
                throw new DomainException('Quantité invalide');
            }
            $this->tenant->assertWarehouseAccess($fromWarehouseId);
            $this->tenant->assertWarehouseAccess($toWarehouseId);
            $from = $this->lineForUpdate($productId, $fromWarehouseId);
            if ($this->available($from) < $quantity) {
                throw new DomainException('Stock insuffisant');
            }
            $from['quantity'] = (int) $from['quantity'] - $quantity;
            $this->stock->put($from);
            $to = $this->ensureLine($productId, $toWarehouseId);
            $to = $this->stock->findForUpdate($to['id']) ?? $to;
            $to['quantity'] = (int) $to['quantity'] + $quantity;
            $this->stock->put($to);
            $this->move('transfer', $productId, $quantity, $userId, $note, $fromWarehouseId, $toWarehouseId);
            $this->notifier->audit('Transfert de stock', 'stock', $productId, $userId);
        });
    }

    public function adjust(string $productId, string $warehouseId, int $quantity, string $userId, string $note, ?string $reference = null): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $note, $reference) {
            if (! $quantity) {
                throw new DomainException('Écart nul');
            }
            $this->tenant->assertWarehouseAccess($warehouseId);
            $piece = StockDocuments::assertInventoryAdjust($reference, $note);
            $item = $this->line($productId, $warehouseId);
            $next = (int) $item['quantity'] + $quantity;
            if ($next < 0) {
                throw new DomainException('Stock insuffisant');
            }
            $item['quantity'] = $next;
            $this->stock->put($item);
            $this->move('adjust', $productId, $quantity, $userId, $note, $warehouseId, null, $piece);
            $this->notifier->audit('Ajustement de stock', 'stock', $productId, $userId);
        });
    }

    public function returnStock(string $productId, string $warehouseId, int $quantity, string $userId, string $note, ?string $reference): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $note, $reference) {
            if ($quantity <= 0) {
                throw new DomainException('Quantité invalide');
            }
            $this->tenant->assertWarehouseAccess($warehouseId);
            $item = $this->ensureLine($productId, $warehouseId);
            $item['quantity'] = (int) $item['quantity'] + $quantity;
            $this->stock->put($item);
            $this->move('return', $productId, $quantity, $userId, $note, null, $warehouseId, $reference);
            $this->notifier->audit('Retour en stock', 'stock', $productId, $userId);
        });
    }

    public function toggleAssignment(string $productId, string $warehouseId, bool $enabled): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $enabled) {
            $this->tenant->assertWarehouseAccess($warehouseId);
            $existing = $this->tryLine($productId, $warehouseId);
            if (! $existing) {
                $this->stock->put([
                    'id' => Uid::make('stk'),
                    'productId' => $productId,
                    'warehouseId' => $warehouseId,
                    'quantity' => 0,
                    'reserved' => 0,
                    'enabled' => $enabled,
                ]);

                return;
            }
            $existing['enabled'] = $enabled;
            $this->stock->put($existing);
        });
    }

    public function startInventory(string $warehouseId, string $userId, ?array $scope = null): array
    {
        return DB::transaction(function () use ($warehouseId, $userId, $scope) {
            $this->tenant->assertWarehouseAccess($warehouseId);
            $open = $this->inventories->openForWarehouse($warehouseId);
            if ($open) {
                $open['lines'] = $open['lines'] ?? [];

                return $this->inventories->put($open);
            }
            $normalized = $this->normalizeInventoryScope($scope);
            $rows = $this->filterStockForInventory($warehouseId, $normalized);
            $lines = $rows->map(fn (array $row) => [
                'productId' => $row['productId'],
                'expected' => (int) $row['quantity'],
                'counted' => (int) $row['quantity'],
            ])->values()->all();
            if (! $lines) {
                throw new DomainException(
                    $normalized
                        ? 'Aucun article dans ce périmètre de cycle count.'
                        : 'Aucun article actif sur cet entrepôt.'
                );
            }
            $mode = $normalized ? 'partial' : 'full';
            $session = $this->tenant->stamp([
                'id' => Uid::make('inv'),
                'warehouseId' => $warehouseId,
                'status' => 'in_progress',
                'mode' => $mode,
                'scope' => $normalized,
                'startedAt' => now()->toIso8601String(),
                'userId' => $userId,
                'lines' => $lines,
                ...$this->tally($lines),
            ]);
            $this->inventories->put($session);
            $this->notifier->audit(
                $mode === 'partial' ? 'Cycle count démarré' : 'Inventaire démarré',
                'inventory',
                $session['id'],
                $userId,
            );

            return $session;
        });
    }

    public function saveInventory(string $id, array $lines): array
    {
        return DB::transaction(function () use ($id, $lines) {
            $session = $this->inventories->findOrFail($id, 'Inventaire introuvable');
            if (($session['status'] ?? '') !== 'in_progress') {
                throw new DomainException('Ce comptage n’est plus modifiable');
            }
            $session['lines'] = array_map(function (array $line) use ($lines) {
                $next = collect($lines)->firstWhere('productId', $line['productId']);

                return $next ? [...$line, 'counted' => max(0, (int) $next['counted'])] : $line;
            }, $session['lines'] ?? []);
            $session = [...$session, ...$this->tally($session['lines'])];

            return $this->inventories->put($session);
        });
    }

    public function submitInventory(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $session = $this->inventories->findOrFail($id, 'Inventaire introuvable');
            if (($session['status'] ?? '') !== 'in_progress') {
                throw new DomainException('Inventaire déjà soumis ou clôturé');
            }
            $gaps = collect($session['lines'] ?? [])->filter(fn (array $line) => $line['counted'] !== $line['expected']);
            if ($gaps->isEmpty()) {
                throw new DomainException('Aucun écart : clôturez directement.');
            }
            $session['status'] = 'pending_review';
            $session['countedByUserId'] = $userId;
            $session['userId'] = $userId;
            $this->inventories->put($session);
            $this->notifier->audit('Écarts d’inventaire soumis', 'inventory', $session['id'], $userId);

            return $session;
        });
    }

    public function completeInventory(string $id, string $userId): array
    {
        return DB::transaction(function () use ($id, $userId) {
            $session = $this->inventories->findOrFail($id, 'Inventaire introuvable');
            if (($session['status'] ?? '') === 'completed') {
                throw new DomainException('Inventaire déjà clôturé');
            }
            $actor = $this->users->find($userId);
            $gaps = collect($session['lines'] ?? [])->filter(fn (array $line) => $line['counted'] !== $line['expected']);
            $canApprove = in_array($actor['role'] ?? '', ['ADMIN', 'MANAGER', 'SUPER_ADMIN'], true);
            if (($session['status'] ?? '') === 'in_progress' && $gaps->isNotEmpty()) {
                throw new DomainException('Les écarts doivent d’abord être soumis pour validation.');
            }
            if (($session['status'] ?? '') === 'pending_review' && ! $canApprove) {
                throw new DomainException('Un manager ou un administrateur doit valider les écarts.');
            }
            foreach ($session['lines'] ?? [] as $row) {
                $delta = (int) $row['counted'] - (int) $row['expected'];
                if (! $delta) {
                    continue;
                }
                $item = $this->line($row['productId'], $session['warehouseId']);
                $next = (int) $item['quantity'] + $delta;
                if ($next < 0) {
                    throw new DomainException('Stock insuffisant');
                }
                $item['quantity'] = $next;
                $this->stock->put($item);
                $ref = 'PV-'.strtoupper(preg_replace('/^inv-/', '', $session['id']));
                $note = (($session['mode'] ?? 'full') === 'partial' ? 'Cycle count ' : 'Inventaire ').$session['id'];
                $this->move('adjust', $row['productId'], $delta, $userId, $note, $session['warehouseId'], null, $ref);
            }
            $session['status'] = 'completed';
            $session['completedAt'] = now()->toIso8601String();
            $session['userId'] = $userId;
            if ($gaps->isNotEmpty()) {
                $session['approvedByUserId'] = $userId;
            } else {
                $session['countedByUserId'] = $session['countedByUserId'] ?? $userId;
            }
            $session = [...$session, ...$this->tally($session['lines'] ?? [])];
            $this->inventories->put($session);
            $this->notifier->audit('Inventaire clôturé', 'inventory', $session['id'], $userId);

            return $session;
        });
    }

    public function closeDay(string $warehouseId, string $userId): void
    {
        $this->tenant->assertWarehouseAccess($warehouseId);
        $stamp = $warehouseId.'|'.now()->toDateString();
        $already = $this->users->auditHas('Clôture de journée', $stamp);
        if ($already) {
            throw new DomainException('Cette journée est déjà clôturée.');
        }
        $this->notifier->audit('Clôture de journée', 'warehouse', $stamp, $userId);
    }

    /**
     * Débit origine pour un transfert logistique (crédit destination à l’arrivée).
     */
    public function debitTransferOutbound(
        string $productId,
        string $fromWarehouseId,
        string $toWarehouseId,
        int $quantity,
        string $userId,
        string $note,
        ?string $reference = null,
        bool $consumeReservation = false,
    ): void {
        $this->tenant->assertWarehouseAccess($fromWarehouseId);
        $this->tenant->assertWarehouseAccess($toWarehouseId);
        if ($quantity <= 0) {
            throw new DomainException('Quantité invalide');
        }
        $from = $this->lineForUpdate($productId, $fromWarehouseId);
        if ($consumeReservation) {
            $reserved = (int) ($from['reserved'] ?? 0);
            if ($reserved < $quantity) {
                throw new DomainException('Réservation insuffisante pour le transfert');
            }
            if ((int) $from['quantity'] < $quantity) {
                throw new DomainException('Stock insuffisant');
            }
            $from['reserved'] = $reserved - $quantity;
        } elseif ($this->available($from) < $quantity) {
            throw new DomainException('Stock insuffisant');
        }
        $from['quantity'] = (int) $from['quantity'] - $quantity;
        $this->stock->put($from);
        $this->move('transfer', $productId, $quantity, $userId, $note, $fromWarehouseId, $toWarehouseId, $reference);
    }

    /**
     * Réserve du stock disponible (affectation BL).
     */
    public function reserve(string $productId, string $warehouseId, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException('Quantité invalide');
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $item = $this->lineForUpdate($productId, $warehouseId);
        if ($this->available($item) < $quantity) {
            throw new DomainException('Stock disponible insuffisant pour réservation');
        }
        $item['reserved'] = (int) ($item['reserved'] ?? 0) + $quantity;
        $this->stock->put($item);
    }

    /**
     * Libère une réservation (désaffectation BL non chargé).
     */
    public function release(string $productId, string $warehouseId, int $quantity): void
    {
        if ($quantity <= 0) {
            return;
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $item = $this->tryLine($productId, $warehouseId);
        if (! $item) {
            return;
        }
        $item = $this->stock->findForUpdate($item['id']) ?? $item;
        $item['reserved'] = max(0, (int) ($item['reserved'] ?? 0) - $quantity);
        $this->stock->put($item);
    }

    /**
     * Consomme une réservation et sort le stock physique (chargement BL).
     */
    public function consumeReservation(
        string $productId,
        string $warehouseId,
        int $quantity,
        string $userId,
        string $note,
        ?string $reference = null,
    ): void {
        if ($quantity <= 0) {
            throw new DomainException('Quantité invalide');
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $item = $this->lineForUpdate($productId, $warehouseId);
        $reserved = (int) ($item['reserved'] ?? 0);
        if ($reserved < $quantity) {
            throw new DomainException('Réservation insuffisante');
        }
        if ((int) $item['quantity'] < $quantity) {
            throw new DomainException('Stock insuffisant');
        }
        $this->consumeLotsIfTracked($productId, $warehouseId, $quantity);
        $item['reserved'] = $reserved - $quantity;
        $item['quantity'] = (int) $item['quantity'] - $quantity;
        $this->stock->put($item);
        $this->move('issue', $productId, $quantity, $userId, $note, $warehouseId, null, $reference);
    }

    public function available(array $item): int
    {
        return max(0, (int) ($item['quantity'] ?? 0) - (int) ($item['reserved'] ?? 0));
    }

    public function receiveWithLot(
        string $productId,
        string $warehouseId,
        int $quantity,
        string $userId,
        string $note,
        ?string $reference,
        ?string $lotCode = null,
        ?string $expiryDate = null,
    ): void {
        DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $note, $reference, $lotCode, $expiryDate) {
            $this->receive($productId, $warehouseId, $quantity, $userId, $note, $reference);
            if ($lotCode || $this->tracksLots($productId)) {
                $this->lots->receive($productId, $warehouseId, $quantity, (string) $lotCode, $expiryDate);
            }
        });
    }

    private function tracksLots(string $productId): bool
    {
        $product = $this->products->find($productId);

        return ! empty($product['trackLots']);
    }

    private function consumeLotsIfTracked(string $productId, string $warehouseId, int $quantity): void
    {
        if ($this->tracksLots($productId)) {
            $this->lots->consumeFefo($productId, $warehouseId, $quantity);
        }
        $this->placements->consumePath($productId, $warehouseId, $quantity);
    }

    /**
     * Crédit destination à la réception d’un transfert inter-sites.
     */
    public function creditTransferInbound(
        string $productId,
        string $toWarehouseId,
        int $quantity,
        string $userId,
        string $note,
        ?string $fromWarehouseId = null,
        ?string $reference = null,
    ): void {
        $this->tenant->assertWarehouseAccess($toWarehouseId);
        if ($quantity <= 0) {
            throw new DomainException('Quantité invalide');
        }
        $to = $this->ensureLine($productId, $toWarehouseId);
        $to['quantity'] = (int) $to['quantity'] + $quantity;
        $this->stock->put($to);
        $this->move('receive', $productId, $quantity, $userId, $note, $fromWarehouseId, $toWarehouseId, $reference);
    }

    public function applyDelta(string $productId, string $warehouseId, int $delta, string $type, string $userId, string $note, ?string $from, ?string $to, ?string $reference): void
    {
        $item = $this->line($productId, $warehouseId);
        $next = (int) $item['quantity'] + $delta;
        if ($next < 0) {
            throw new DomainException('Stock insuffisant');
        }
        $item['quantity'] = $next;
        $this->stock->put($item);
        $this->move($type, $productId, abs($delta), $userId, $note, $from, $to, $reference);
    }

    public function line(string $productId, string $warehouseId): array
    {
        $item = $this->tryLine($productId, $warehouseId);
        if (! $item) {
            throw new DomainException('Ligne de stock introuvable');
        }

        return $item;
    }

    public function lineForUpdate(string $productId, string $warehouseId): array
    {
        $locked = $this->stock->findByProductWarehouseForUpdate($productId, $warehouseId);
        if (! $locked) {
            throw new DomainException('Ligne de stock introuvable');
        }

        return $locked;
    }

    public function tryLine(string $productId, string $warehouseId): ?array
    {
        return $this->stock->findByProductWarehouse($productId, $warehouseId);
    }

    public function ensureLine(string $productId, string $warehouseId): array
    {
        $item = $this->stock->findByProductWarehouseForUpdate($productId, $warehouseId);
        if ($item) {
            return $item;
        }

        return $this->stock->put([
            'id' => Uid::make('stk'),
            'productId' => $productId,
            'warehouseId' => $warehouseId,
            'quantity' => 0,
            'reserved' => 0,
            'enabled' => true,
        ]);
    }

    public function putLine(array $item): array
    {
        return $this->stock->put($item);
    }

    public function move(
        string $type,
        string $productId,
        int $quantity,
        string $userId,
        string $note,
        ?string $fromWarehouseId = null,
        ?string $toWarehouseId = null,
        ?string $reference = null,
    ): array {
        return $this->movements->put([
            'id' => Uid::make('mov'),
            'productId' => $productId,
            'type' => $type,
            'quantity' => $quantity,
            'fromWarehouseId' => $fromWarehouseId,
            'toWarehouseId' => $toWarehouseId,
            'userId' => $userId,
            'date' => now()->toIso8601String(),
            'note' => $note,
            'reference' => $reference,
        ]);
    }

    private function tally(array $lines): array
    {
        return [
            'expected' => collect($lines)->sum('expected'),
            'counted' => collect($lines)->sum('counted'),
        ];
    }

    private function normalizeInventoryScope(?array $scope): ?array
    {
        if (! is_array($scope)) {
            return null;
        }
        $next = [];
        if (! empty($scope['categoryId'])) {
            $next['categoryId'] = (string) $scope['categoryId'];
        }
        if (! empty($scope['locationId'])) {
            $next['locationId'] = (string) $scope['locationId'];
        }
        if (! empty($scope['aisle']) && trim((string) $scope['aisle']) !== '') {
            $next['aisle'] = trim((string) $scope['aisle']);
        }
        if (! empty($scope['productIds']) && is_array($scope['productIds'])) {
            $ids = array_values(array_unique(array_filter(array_map('strval', $scope['productIds']))));
            if ($ids) {
                $next['productIds'] = $ids;
            }
        }

        return $next ?: null;
    }

    private function filterStockForInventory(string $warehouseId, ?array $scope): Collection
    {
        $products = $this->products->all()->keyBy('id');
        $productIds = null;

        if (! empty($scope['productIds'])) {
            $productIds = collect($scope['productIds'])->flip();
        }
        if (! empty($scope['categoryId'])) {
            $inCategory = $products
                ->filter(fn (array $item) => ($item['categoryId'] ?? '') === $scope['categoryId'] && empty($item['archived']))
                ->keys();
            $productIds = $productIds
                ? collect($productIds->keys())->intersect($inCategory)->flip()
                : $inCategory->flip();
        }
        if (! empty($scope['locationId']) || ! empty($scope['aisle'])) {
            $locationIds = $this->locations->forWarehouse($warehouseId)
                ->filter(function (array $loc) use ($scope) {
                    if (($loc['active'] ?? true) === false) {
                        return false;
                    }
                    if (! empty($scope['locationId']) && ($loc['id'] ?? '') !== $scope['locationId']) {
                        return false;
                    }
                    if (! empty($scope['aisle']) && ($loc['aisle'] ?? '') !== $scope['aisle']) {
                        return false;
                    }

                    return true;
                })
                ->pluck('id')
                ->flip();
            $placed = $this->placements->list($warehouseId)
                ->filter(fn (array $row) => (int) ($row['quantity'] ?? 0) > 0
                    && ! empty($row['locationId'])
                    && $locationIds->has($row['locationId']))
                ->pluck('productId')
                ->unique()
                ->values();
            $productIds = $productIds
                ? collect($productIds->keys())->intersect($placed)->flip()
                : $placed->flip();
        }

        return $this->stock->all()->filter(function (array $row) use ($warehouseId, $productIds, $products) {
            if (($row['warehouseId'] ?? '') !== $warehouseId || ($row['enabled'] ?? true) === false) {
                return false;
            }
            if ($productIds && ! $productIds->has($row['productId'] ?? '')) {
                return false;
            }
            $product = $products->get($row['productId'] ?? '');
            if ($product && ! empty($product['archived'])) {
                return false;
            }

            return true;
        })->values();
    }
}
