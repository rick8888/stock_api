<?php

namespace App\Services;

use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\ReceiptRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReceiptService
{
    public function __construct(
        private readonly ReceiptRepository $receipts,
        private readonly PurchaseOrderRepository $orders,
        private readonly ProcurementRequestRepository $requests,
        private readonly StockService $stock,
        private readonly OpsNotifier $notifier,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->receipts->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->receipts->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            if (! empty($input['warehouseId'])) {
                $this->tenant->assertWarehouseAccess($input['warehouseId']);
            }
            $seq = 10 + $this->receipts->all()->count() + 1;
            $year = now()->format('Y');
            $now = now()->toIso8601String();
            $receipt = $this->receipts->put($this->tenant->stamp([
                ...$input,
                'id' => Uid::make('rcp'),
                'code' => $input['code'] ?? 'BR-'.$year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
                'date' => $input['date'] ?? $now,
            ]));
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
            $this->tenant->assertWarehouseAccess($receipt['warehouseId'] ?? '');
            $order = $this->orders->find($receipt['purchaseOrderId'] ?? '');
            $previous = collect($receipt['items'] ?? [])->mapWithKeys(fn (array $line) => [$line['productId'] => $line['received']]);
            $source = $order['items'] ?? $receipt['items'] ?? [];
            $nextItems = [];
            foreach ($source as $item) {
                $productId = $item['productId'] ?? '';
                $expected = $item['expected'] ?? $item['quantity'] ?? 0;
                $incoming = collect($lines ?? [])->firstWhere('productId', $productId);
                $receivedQty = $incoming['received'] ?? $expected;
                $nextItems[] = [
                    'productId' => $productId,
                    'expected' => $expected,
                    'received' => $receivedQty,
                    'lotCode' => $incoming['lotCode'] ?? null,
                    'expiryDate' => $incoming['expiryDate'] ?? null,
                    'locationId' => $incoming['locationId'] ?? null,
                ];
            }
            if ($lines && ! $nextItems) {
                foreach ($lines as $line) {
                    $nextItems[] = [
                        'productId' => $line['productId'],
                        'expected' => $line['received'],
                        'received' => $line['received'],
                        'lotCode' => $line['lotCode'] ?? null,
                        'expiryDate' => $line['expiryDate'] ?? null,
                        'locationId' => $line['locationId'] ?? null,
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
                $item = $this->stock->ensureLine($line['productId'], $receipt['warehouseId']);
                $item['quantity'] = (int) $item['quantity'] + $delta;
                $this->stock->putLine($item);
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
                $lotCode = $line['lotCode'] ?? null;
                $expiryDate = $line['expiryDate'] ?? null;
                $locationId = $line['locationId'] ?? null;
                $lotId = null;
                if ($lotCode || $expiryDate) {
                    $lot = app(StockLotService::class)->receive(
                        $line['productId'],
                        $receipt['warehouseId'],
                        $delta,
                        (string) ($lotCode ?: ''),
                        $expiryDate,
                        $locationId,
                    );
                    $lotId = $lot['id'] ?? null;
                }
                app(StockPlacementService::class)->putaway(
                    $line['productId'],
                    $receipt['warehouseId'],
                    $delta,
                    $locationId,
                    $lotId,
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
