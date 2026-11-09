<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\InventorySessionRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\StockMovementRepository;
use App\Support\StockDocuments;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(
        private readonly StockItemRepository $stock,
        private readonly StockMovementRepository $movements,
        private readonly InventorySessionRepository $inventories,
        private readonly OpsNotifier $notifier,
        private readonly UserDirectory $users,
    ) {}

    public function list(): Collection
    {
        return $this->stock->all();
    }

    public function movements(): Collection
    {
        return $this->movements->all();
    }

    public function inventories(): Collection
    {
        return $this->inventories->all();
    }

    public function receive(string $productId, string $warehouseId, int $quantity, string $userId, string $note, ?string $reference): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $quantity, $userId, $note, $reference) {
            if ($quantity <= 0) {
                throw new DomainException('Quantité invalide');
            }
            $piece = StockDocuments::assertInbound($reference, $note);
            $item = $this->line($productId, $warehouseId);
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
            $piece = StockDocuments::assertOutbound($reference, $note);
            $item = $this->line($productId, $warehouseId);
            if ((int) $item['quantity'] < $quantity) {
                throw new DomainException('Stock insuffisant');
            }
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
            $from = $this->line($productId, $fromWarehouseId);
            if ((int) $from['quantity'] < $quantity) {
                throw new DomainException('Stock insuffisant');
            }
            $from['quantity'] = (int) $from['quantity'] - $quantity;
            $this->stock->put($from);
            $to = $this->line($productId, $toWarehouseId);
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
            $item = $this->line($productId, $warehouseId);
            $item['quantity'] = (int) $item['quantity'] + $quantity;
            $this->stock->put($item);
            $this->move('return', $productId, $quantity, $userId, $note, null, $warehouseId, $reference);
            $this->notifier->audit('Retour en stock', 'stock', $productId, $userId);
        });
    }

    public function toggleAssignment(string $productId, string $warehouseId, bool $enabled): void
    {
        DB::transaction(function () use ($productId, $warehouseId, $enabled) {
            $existing = $this->stock->all()->first(
                fn (array $row) => $row['productId'] === $productId && $row['warehouseId'] === $warehouseId,
            );
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

    public function startInventory(string $warehouseId, string $userId): array
    {
        return DB::transaction(function () use ($warehouseId, $userId) {
            $open = $this->inventories->all()->first(
                fn (array $item) => $item['warehouseId'] === $warehouseId && ($item['status'] ?? '') !== 'completed',
            );
            if ($open) {
                $open['lines'] = $open['lines'] ?? [];

                return $this->inventories->put($open);
            }
            $lines = $this->stock->all()
                ->filter(fn (array $row) => $row['warehouseId'] === $warehouseId && ($row['enabled'] ?? true) !== false)
                ->map(fn (array $row) => [
                    'productId' => $row['productId'],
                    'expected' => (int) $row['quantity'],
                    'counted' => (int) $row['quantity'],
                ])
                ->values()
                ->all();
            if (! $lines) {
                throw new DomainException('Aucun article actif sur cet entrepôt.');
            }
            $session = [
                'id' => Uid::make('inv'),
                'warehouseId' => $warehouseId,
                'status' => 'in_progress',
                'startedAt' => now()->toIso8601String(),
                'userId' => $userId,
                'lines' => $lines,
                ...$this->tally($lines),
            ];
            $this->inventories->put($session);
            $this->notifier->audit('Inventaire démarré', 'inventory', $session['id'], $userId);

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
                $this->move('adjust', $row['productId'], $delta, $userId, 'Inventaire '.$session['id'], $session['warehouseId'], null, $ref);
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
        $stamp = $warehouseId.'|2026-08-26';
        $already = $this->users->auditHas('Clôture de journée', $stamp);
        if ($already) {
            throw new DomainException('Cette journée est déjà clôturée.');
        }
        $this->notifier->audit('Clôture de journée', 'warehouse', $stamp, $userId);
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
        $item = $this->stock->all()->first(
            fn (array $row) => $row['productId'] === $productId && $row['warehouseId'] === $warehouseId,
        );
        if (! $item) {
            throw new DomainException('Ligne de stock introuvable');
        }

        return $item;
    }

    public function tryLine(string $productId, string $warehouseId): ?array
    {
        return $this->stock->all()->first(
            fn (array $row) => $row['productId'] === $productId && $row['warehouseId'] === $warehouseId,
        );
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
}
