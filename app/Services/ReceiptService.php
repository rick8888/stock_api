<?php

namespace App\Services;

use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReceiptService
{
    public function __construct(
        private readonly ReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly ProcurementRequestRepository $requests,
        private readonly StockItemRepository $stockItems,
        private readonly StockService $stock,
        private readonly OpsNotifier $notifier,
    ) {}

    public function list(): Collection
    {
        return $this->receipts->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $seq = 10 + $this->receipts->all()->count() + 1;
            $now = now()->toIso8601String();
            $receipt = $this->receipts->put([
                ...$input,
                'id' => Uid::make('rcp'),
                'code' => $input['code'] ?? 'BR-2026-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'date' => $input['date'] ?? $now,
            ]);
            $order = $this->orders->find($receipt['purchaseOrderId'] ?? '');
            $this->notifier->alert([
                'title' => ($receipt['code'] ?? 'BR').' à réceptionner',
                'message' => 'Arrivage prévu pour '.($order['code'] ?? strtoupper($receipt['purchaseOrderId'] ?? '')).'.',
                'severity' => 'warning',
                'domain' => 'stock',
                'relatedId' => $receipt['id'],
                'warehouseId' => $receipt['warehouseId'] ?? null,
                'roles' => ['WAREHOUSE_MANAGER'],
            ]);
            $this->notifier->notify('Réception à faire', ($receipt['code'] ?? 'BR').' — contrôler puis valider l’arrivage.', 'warning');

            return $receipt;
        });
    }

    public function validate(string $id, int $received, string $userId, ?array $lines = null): array
    {
        return DB::transaction(function () use ($id, $received, $userId, $lines) {
            $receipt = $this->receipts->findOrFail($id, 'Réception introuvable');
            $order = $this->orders->find($receipt['purchaseOrderId'] ?? '');
            $previous = collect($receipt['items'] ?? [])->mapWithKeys(fn (array $line) => [$line['productId'] => $line['received']]);
            $source = $order['items'] ?? $receipt['items'] ?? [];
            $nextItems = [];
            foreach ($source as $item) {
                $productId = $item['productId'] ?? '';
                $expected = $item['expected'] ?? $item['quantity'] ?? 0;
                $incoming = collect($lines ?? [])->firstWhere('productId', $productId);
                $receivedQty = $incoming['received'] ?? $expected;
                $nextItems[] = ['productId' => $productId, 'expected' => $expected, 'received' => $receivedQty];
            }
            if ($lines && ! $nextItems) {
                foreach ($lines as $line) {
                    $nextItems[] = [
                        'productId' => $line['productId'],
                        'expected' => $line['received'],
                        'received' => $line['received'],
                    ];
                }
            }
            $receipt['items'] = $nextItems;
            $receipt['received'] = collect($nextItems)->sum('received') ?: $received;
            $receipt['expected'] = collect($nextItems)->sum('expected') ?: ($receipt['expected'] ?? 0);
            $receipt['status'] = $receipt['received'] >= $receipt['expected'] && $receipt['expected'] > 0 ? 'validated' : 'partial';
            $receipt['userId'] = $userId;
            $receipt['checkedAt'] = now()->toIso8601String();
            $receipt['date'] = $receipt['checkedAt'];
            $this->receipts->put($receipt);
            if ($order) {
                $order['status'] = $receipt['status'] === 'validated' ? 'received' : 'partial';
                $this->orders->put($order);
                if ($receipt['status'] === 'validated') {
                    $requestIds = $order['procurementRequestIds'] ?? [];
                    if (! $requestIds && ! empty($order['procurementRequestId'])) {
                        $requestIds = [$order['procurementRequestId']];
                    }
                    foreach ($this->requests->all() as $request) {
                        if (in_array($request['id'], $requestIds, true)) {
                            $request['status'] = 'closed';
                            $this->requests->put($request);
                        }
                    }
                }
            }
            foreach ($nextItems as $line) {
                $delta = (int) $line['received'] - (int) ($previous[$line['productId']] ?? 0);
                if ($delta <= 0) {
                    continue;
                }
                $item = $this->stock->tryLine($line['productId'], $receipt['warehouseId']);
                if ($item) {
                    $item['quantity'] = (int) $item['quantity'] + $delta;
                    $this->stockItems->put($item);
                }
                $this->stock->move(
                    'receive',
                    $line['productId'],
                    $delta,
                    $userId,
                    'Réception '.($receipt['code'] ?? $receipt['id']),
                    null,
                    $receipt['warehouseId'],
                    $receipt['code'] ?? $receipt['id'],
                );
            }
            $this->notifier->alert([
                'title' => $receipt['status'] === 'validated' ? ($receipt['code'].' validé') : ($receipt['code'].' partiel'),
                'message' => $receipt['status'] === 'validated'
                    ? 'Stock mis à jour, demande clôturée.'
                    : 'Écart constaté — le reste reste attendu.',
                'severity' => $receipt['status'] === 'validated' ? 'info' : 'warning',
                'domain' => 'stock',
                'relatedId' => $receipt['id'],
                'warehouseId' => $receipt['warehouseId'] ?? null,
                'roles' => ['WAREHOUSE_MANAGER', 'PROCUREMENT'],
            ]);

            return $receipt;
        });
    }
}
