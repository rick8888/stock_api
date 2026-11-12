<?php

namespace App\Services;

use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\SupplierRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use App\Support\DocumentCodes;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Board /procurement — overviews + compteurs + pagination serveur.
 */
class ProcurementBoardService
{
    private const OPEN_ORDERS = ['draft', 'sent', 'confirmed', 'partial', 'delayed'];

    public function __construct(
        private readonly ProcurementService $procurement,
        private readonly PurchaseService $purchases,
        private readonly ReceiptService $receipts,
        private readonly ConsultationService $consultations,
        private readonly ProductRepository $products,
        private readonly WarehouseRepository $warehouses,
        private readonly SupplierRepository $suppliers,
        private readonly StockItemRepository $stock,
        private readonly UserDirectory $users,
        private readonly Tenant $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(string $section = 'requests', ?string $filter = null, int $page = 1, int $pageSize = 8, ?string $focusId = null): array
    {
        $pageSize = max(1, min(50, $pageSize));
        $section = in_array($section, ['requests', 'orders', 'receipts', 'suppliers'], true) ? $section : 'requests';

        $payload = match ($section) {
            'orders' => $this->ordersBoard($filter ?: 'open', $page, $pageSize, $focusId),
            'receipts' => $this->receiptsBoard($filter ?: 'pending', $page, $pageSize, $focusId),
            'suppliers' => $this->suppliersBoard($filter ?: 'all', $page, $pageSize, $focusId),
            default => $this->requestsBoard($filter ?: 'all', $page, $pageSize, $focusId),
        };
        $payload['formOptions'] = $this->formOptions();

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    public function requestOverviewsPublic(): array
    {
        return $this->requestOverviews();
    }

    /** @return array<string, mixed> */
    private function requestsBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->requestOverviews();
        $counts = [
            'all' => count($rows),
            'submitted' => 0,
            'in_review' => 0,
            'approved' => 0,
            'rejected' => 0,
            'ordered' => 0,
            'expired' => 0,
        ];
        foreach ($rows as $row) {
            $status = $this->normalizeStatus((string) ($row['request']['status'] ?? ''));
            if ($status === 'submitted') {
                $counts['submitted']++;
            } elseif ($status === 'in_review') {
                $counts['in_review']++;
            } elseif ($status === 'approved') {
                $counts['approved']++;
            } elseif ($status === 'rejected') {
                $counts['rejected']++;
            } elseif (in_array($status, ['ordered', 'closed'], true)) {
                $counts['ordered']++;
            } elseif ($status === 'expired') {
                $counts['expired']++;
            }
        }

        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            if ($filter === 'all') {
                return true;
            }
            $status = $this->normalizeStatus((string) ($row['request']['status'] ?? ''));
            if ($filter === 'ordered') {
                return in_array($status, ['ordered', 'closed'], true);
            }
            if ($filter === 'submitted') {
                return $status === 'submitted';
            }

            return $status === $filter;
        }));

        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['request']['id'] ?? ''));

        $actorId = $this->tenant->actorId();
        $ready = array_values(array_filter($rows, fn (array $row) => $this->isReadyToPurchase($row, $actorId)));

        return [
            'section' => 'requests',
            'filter' => $filter,
            'counts' => $counts,
            'readyToPurchase' => $ready,
            ...$pageData,
        ];
    }

    /** @return array<string, mixed> */
    private function ordersBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->orderOverviews();
        $counts = [
            'open' => 0,
            'delayed' => 0,
            'received' => 0,
            'all' => count($rows),
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['order']['status'] ?? '');
            if (! in_array($status, ['received', 'cancelled'], true)) {
                $counts['open']++;
            }
            if (! empty($row['late'])) {
                $counts['delayed']++;
            }
            if ($status === 'received') {
                $counts['received']++;
            }
        }

        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            $status = (string) ($row['order']['status'] ?? '');
            if ($filter === 'all') {
                return true;
            }
            if ($filter === 'delayed') {
                return ! empty($row['late']);
            }
            if ($filter === 'received') {
                return $status === 'received';
            }

            return ! in_array($status, ['received', 'cancelled'], true);
        }));

        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['order']['id'] ?? ''));

        return [
            'section' => 'orders',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /** @return array<string, mixed> */
    private function suppliersBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->scopedSuppliersList();
        if ($filter === 'active') {
            $rows = array_values(array_filter($rows, fn (array $s) => ($s['status'] ?? '') === 'active'));
        } elseif ($filter === 'pending') {
            $rows = array_values(array_filter($rows, fn (array $s) => ($s['status'] ?? '') !== 'active'));
        }

        $all = $this->scopedSuppliersList();
        $counts = [
            'all' => count($all),
            'active' => count(array_filter($all, fn (array $s) => ($s['status'] ?? '') === 'active')),
            'pending' => count(array_filter($all, fn (array $s) => ($s['status'] ?? '') !== 'active')),
        ];
        $pageData = $this->paginate($rows, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['id'] ?? ''));

        return [
            'section' => 'suppliers',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /**
     * Options pour formulaires DA / consultation — pas de métier, juste le référentiel scoppé.
     *
     * @return array{
     *   products: list<array<string, mixed>>,
     *   warehouses: list<array<string, mixed>>,
     *   suppliers: list<array<string, mixed>>
     * }
     */
    private function formOptions(): array
    {
        $products = [];
        foreach ($this->products->all() as $product) {
            if (! empty($product['archived'])) {
                continue;
            }
            $products[] = [
                'id' => (string) ($product['id'] ?? ''),
                'name' => (string) ($product['name'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'unit' => (string) ($product['unit'] ?? 'unité'),
                'price' => (float) ($product['price'] ?? 0),
                'barcode' => $product['barcode'] ?? null,
                'supplierId' => $product['supplierId'] ?? null,
                'archived' => false,
            ];
        }
        $warehouses = [];
        $scope = $this->userWarehouseScope();
        foreach ($this->warehouses->all() as $warehouse) {
            $id = (string) ($warehouse['id'] ?? '');
            if ($scope && ! in_array($id, $scope, true)) {
                continue;
            }
            if (isset($warehouse['active']) && $warehouse['active'] === false) {
                continue;
            }
            $warehouses[] = [
                'id' => $id,
                'name' => (string) ($warehouse['name'] ?? $id),
            ];
        }

        $suppliers = [];
        foreach ($this->scopedSuppliersList() as $supplier) {
            $suppliers[] = [
                'id' => (string) ($supplier['id'] ?? ''),
                'name' => (string) ($supplier['name'] ?? ''),
                'contact' => (string) ($supplier['contact'] ?? ''),
                'email' => (string) ($supplier['email'] ?? ''),
                'phone' => (string) ($supplier['phone'] ?? ''),
                'city' => (string) ($supplier['city'] ?? ''),
                'category' => $supplier['category'] ?? null,
                'paymentTerms' => $supplier['paymentTerms'] ?? null,
                'leadTimeDays' => (int) ($supplier['leadTimeDays'] ?? 0),
                'reliability' => (int) ($supplier['reliability'] ?? 0),
                'status' => (($supplier['status'] ?? '') === 'active') ? 'active' : 'pending',
            ];
        }

        return ['products' => $products, 'warehouses' => $warehouses, 'suppliers' => $suppliers];
    }

    /** @return list<array<string, mixed>> */
    private function scopedSuppliersList(): array
    {
        $rows = $this->tenant->bypassTenantFilter()
            ? $this->suppliers->all()
            : $this->suppliers->forCompany($this->tenant->companyId());

        return $rows->sortBy('name')->values()->all();
    }

    /** @return array<string, mixed> */
    private function receiptsBoard(string $filter, int $page, int $pageSize, ?string $focusId): array
    {
        $rows = $this->receiptOverviews();
        $counts = [
            'pending' => 0,
            'partial' => 0,
            'validated' => 0,
            'all' => count($rows),
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['receipt']['status'] ?? '');
            if (in_array($status, ['pending', 'checking'], true)) {
                $counts['pending']++;
            } elseif ($status === 'partial') {
                $counts['partial']++;
            } elseif ($status === 'validated') {
                $counts['validated']++;
            }
        }

        $filtered = array_values(array_filter($rows, function (array $row) use ($filter) {
            $status = (string) ($row['receipt']['status'] ?? '');
            if ($filter === 'all') {
                return true;
            }
            if ($filter === 'pending') {
                return in_array($status, ['pending', 'checking'], true);
            }

            return $status === $filter;
        }));

        $pageData = $this->paginate($filtered, $page, $pageSize, $focusId, fn (array $row) => (string) ($row['receipt']['id'] ?? ''));

        return [
            'section' => 'receipts',
            'filter' => $filter,
            'counts' => $counts,
            ...$pageData,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): string  $idOf
     * @return array{page: int, pageSize: int, total: int, pages: int, items: list<array<string, mixed>>, focused: array<string, mixed>|null}
     */
    private function paginate(array $items, int $page, int $pageSize, ?string $focusId, callable $idOf): array
    {
        $total = count($items);
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = max(1, min($page, $pages));
        $focused = null;

        if ($focusId) {
            foreach ($items as $index => $item) {
                if ($idOf($item) === $focusId) {
                    $focused = $item;
                    $page = intdiv($index, $pageSize) + 1;
                    break;
                }
            }
        }

        $slice = array_slice($items, ($page - 1) * $pageSize, $pageSize);

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'pages' => $pages,
            'items' => array_values($slice),
            'focused' => $focused,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function requestOverviews(): array
    {
        $products = $this->products->all()->keyBy('id');
        $warehouses = $this->warehouses->all()->keyBy('id');
        $users = $this->users->all()->keyBy('id');
        $stock = $this->scopedStock()->keyBy(fn (array $row) => ($row['productId'] ?? '').'|'.($row['warehouseId'] ?? ''));
        $orders = $this->scopedOrders();
        $receipts = $this->scopedReceipts();
        $consultations = $this->consultations->list();

        $rows = [];
        foreach ($this->scopedRequests() as $request) {
            $warehouseId = (string) ($request['warehouseId'] ?? '');
            $warehouse = $warehouses->get($warehouseId);
            $requester = $users->get($request['requesterId'] ?? '');
            $lines = [];
            foreach ($request['items'] ?? [] as $line) {
                $productId = (string) ($line['productId'] ?? '');
                $product = $products->get($productId);
                $stockRow = $stock->get($productId.'|'.$warehouseId);
                $available = is_array($stockRow)
                    ? max(0, (int) ($stockRow['quantity'] ?? 0) - (int) ($stockRow['reserved'] ?? 0))
                    : 0;
                $unitPrice = (float) (is_array($product) ? ($product['price'] ?? 0) : 0);
                $qty = (int) ($line['quantity'] ?? 0);
                $lines[] = [
                    'productId' => $productId,
                    'productName' => (string) (is_array($product) ? ($product['name'] ?? $productId) : $productId),
                    'sku' => (string) (is_array($product) ? ($product['sku'] ?? '') : ''),
                    'unit' => (string) (is_array($product) ? ($product['unit'] ?? 'unité') : 'unité'),
                    'quantity' => $qty,
                    'unitPrice' => $unitPrice,
                    'amount' => $unitPrice * $qty,
                    'available' => $available,
                    'inTransit' => $this->inTransit($orders, $productId, $warehouseId),
                ];
            }
            $short = count(array_filter($lines, fn (array $l) => $l['available'] < $l['quantity']));
            $covered = count(array_filter($lines, fn (array $l) => $l['available'] >= $l['quantity']));
            $decision = $short === 0 ? 'issue' : ($covered === 0 ? 'purchase' : 'mixed');
            $linkedOrder = $this->orderForRequest($orders, (string) $request['id']);
            $linkedReceipt = $linkedOrder
                ? $receipts->first(fn (array $r) => ($r['purchaseOrderId'] ?? '') === ($linkedOrder['id'] ?? ''))
                : null;
            $linkedConsultation = $consultations->first(function (array $c) use ($request) {
                $ids = $c['procurementRequestIds'] ?? [];

                return in_array($request['id'], $ids, true) && ($c['status'] ?? '') !== 'cancelled';
            });
            $assignee = $users->get($request['assigneeId'] ?? '');
            $assigneeName = is_array($assignee) ? trim(($assignee['firstName'] ?? '').' '.($assignee['lastName'] ?? '')) : null;

            $rows[] = [
                'request' => $request,
                'code' => DocumentCodes::request((string) $request['id'], $request['code'] ?? null),
                'requesterName' => is_array($requester)
                    ? trim(($requester['firstName'] ?? '').' '.($requester['lastName'] ?? ''))
                    : (string) ($request['requesterId'] ?? ''),
                'warehouseName' => (string) (is_array($warehouse) ? ($warehouse['name'] ?? $warehouseId) : $warehouseId),
                'service' => (string) ($request['service'] ?? (is_array($warehouse) ? ($warehouse['name'] ?? 'Opérations') : 'Opérations')),
                'priority' => (string) ($request['priority'] ?? 'medium'),
                'total' => array_sum(array_column($lines, 'amount')),
                'lines' => $lines,
                'decision' => $decision,
                'timeline' => $this->requestTimeline($request, $linkedOrder ?: null, is_array($linkedReceipt) ? $linkedReceipt : null, $decision, $assigneeName, is_array($linkedConsultation) ? $linkedConsultation : null),
                'linkedOrder' => $linkedOrder ?: null,
                'linkedReceipt' => is_array($linkedReceipt) ? $linkedReceipt : null,
                'linkedConsultation' => is_array($linkedConsultation) ? $linkedConsultation : null,
                'assigneeName' => $assigneeName ?: null,
                'nextOwner' => $this->nextOwnerLabel($request, $decision),
                'readyToPurchase' => false,
            ];
            $rows[array_key_last($rows)]['readyToPurchase'] = $this->isReadyToPurchase($rows[array_key_last($rows)], $this->tenant->actorId());
        }

        usort($rows, fn (array $a, array $b) => strcmp((string) ($b['request']['createdAt'] ?? ''), (string) ($a['request']['createdAt'] ?? '')));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function orderOverviews(): array
    {
        $products = $this->products->all()->keyBy('id');
        $warehouses = $this->warehouses->all()->keyBy('id');
        $suppliers = $this->suppliers->all()->keyBy('id');
        $receipts = $this->scopedReceipts();
        $requests = $this->scopedRequests()->keyBy('id');
        $today = now()->toDateString();
        $rows = [];

        foreach ($this->scopedOrders() as $order) {
            $warehouseId = (string) ($order['warehouseId'] ?? '');
            $supplierId = (string) ($order['supplierId'] ?? '');
            $warehouse = $warehouses->get($warehouseId);
            $supplier = $suppliers->get($supplierId);
            $linkedIds = $this->orderRequestIds($order);
            $linkedRequests = [];
            foreach ($linkedIds as $id) {
                $req = $requests->get($id);
                if (is_array($req)) {
                    $linkedRequests[] = [
                        'id' => $id,
                        'code' => DocumentCodes::request($id, $req['code'] ?? null),
                        'note' => (string) ($req['note'] ?? ''),
                    ];
                }
            }
            $linkedReceipt = $receipts->first(fn (array $r) => ($r['purchaseOrderId'] ?? '') === ($order['id'] ?? ''));
            $lines = [];
            foreach ($order['items'] ?? [] as $line) {
                $productId = (string) ($line['productId'] ?? '');
                $product = $products->get($productId);
                $unitPrice = (float) (is_array($product) ? ($product['price'] ?? 0) : 0);
                $qty = (int) ($line['quantity'] ?? 0);
                $lines[] = [
                    'productId' => $productId,
                    'productName' => (string) (is_array($product) ? ($product['name'] ?? $productId) : $productId),
                    'sku' => (string) (is_array($product) ? ($product['sku'] ?? '') : ''),
                    'unit' => (string) (is_array($product) ? ($product['unit'] ?? 'unité') : 'unité'),
                    'quantity' => $qty,
                    'unitPrice' => $unitPrice,
                    'amount' => $unitPrice * $qty,
                ];
            }
            $late = $this->isOrderLate($order, $today);
            $rows[] = [
                'order' => $order,
                'code' => DocumentCodes::order((string) $order['id'], $order['code'] ?? null),
                'supplierName' => (string) (is_array($supplier) ? ($supplier['name'] ?? $supplierId) : $supplierId),
                'supplierPhone' => is_array($supplier) ? ($supplier['phone'] ?? null) : null,
                'warehouseName' => (string) (is_array($warehouse) ? ($warehouse['name'] ?? $warehouseId) : $warehouseId),
                'lines' => $lines,
                'requests' => $linkedRequests,
                'linkedReceipt' => is_array($linkedReceipt) ? $linkedReceipt : null,
                'receiptCode' => is_array($linkedReceipt)
                    ? DocumentCodes::receipt((string) $linkedReceipt['id'], $linkedReceipt['code'] ?? null)
                    : null,
                'late' => $late,
                'nextAction' => $this->orderNextAction($order, $late, is_array($linkedReceipt) ? $linkedReceipt : null),
                'timeline' => $this->orderTimeline($order, is_array($linkedReceipt) ? $linkedReceipt : null, $late),
                'etaLabel' => $this->orderEtaLabel($order, $late, $today),
            ];
        }

        usort($rows, function (array $a, array $b) {
            if (($a['late'] ?? false) !== ($b['late'] ?? false)) {
                return ($a['late'] ?? false) ? -1 : 1;
            }
            $rank = ['delayed' => 0, 'sent' => 1, 'confirmed' => 2, 'partial' => 3, 'draft' => 4, 'received' => 5, 'cancelled' => 6];

            return ($rank[$a['order']['status'] ?? ''] ?? 9) <=> ($rank[$b['order']['status'] ?? ''] ?? 9)
                ?: strcmp((string) ($a['order']['expectedDate'] ?? ''), (string) ($b['order']['expectedDate'] ?? ''));
        });

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function receiptOverviews(): array
    {
        $products = $this->products->all()->keyBy('id');
        $warehouses = $this->warehouses->all()->keyBy('id');
        $suppliers = $this->suppliers->all()->keyBy('id');
        $orders = $this->scopedOrders()->keyBy('id');
        $requests = $this->scopedRequests()->keyBy('id');
        $rows = [];

        foreach ($this->scopedReceipts() as $receipt) {
            $order = $orders->get($receipt['purchaseOrderId'] ?? '');
            $warehouseId = (string) ($receipt['warehouseId'] ?? '');
            $warehouse = $warehouses->get($warehouseId);
            $supplier = is_array($order) ? $suppliers->get($order['supplierId'] ?? '') : null;
            $linkedIds = is_array($order) ? $this->orderRequestIds($order) : [];
            $linkedCodes = [];
            foreach ($linkedIds as $id) {
                $req = $requests->get($id);
                if (is_array($req)) {
                    $linkedCodes[] = DocumentCodes::request($id, $req['code'] ?? null);
                }
            }
            $sourceLines = ! empty($receipt['items']) && is_array($receipt['items'])
                ? $receipt['items']
                : (is_array($order) ? array_map(function (array $item) use ($receipt, $order) {
                    return [
                        'productId' => $item['productId'] ?? '',
                        'expected' => (int) ($item['quantity'] ?? 0),
                        'received' => ($receipt['status'] ?? '') === 'validated'
                            ? (int) ($item['quantity'] ?? 0)
                            : (count($order['items'] ?? []) === 1 ? (int) ($receipt['received'] ?? 0) : 0),
                    ];
                }, $order['items'] ?? []) : []);

            $lines = [];
            foreach ($sourceLines as $line) {
                $productId = (string) ($line['productId'] ?? '');
                $product = $products->get($productId);
                $lines[] = [
                    'productId' => $productId,
                    'productName' => (string) (is_array($product) ? ($product['name'] ?? $productId) : $productId),
                    'sku' => (string) (is_array($product) ? ($product['sku'] ?? '') : ''),
                    'unit' => (string) (is_array($product) ? ($product['unit'] ?? 'unité') : 'unité'),
                    'expected' => (int) ($line['expected'] ?? 0),
                    'received' => (int) ($line['received'] ?? 0),
                    'barcode' => is_array($product) ? ($product['barcode'] ?? null) : null,
                    'trackLots' => is_array($product) ? ($product['trackLots'] ?? null) : null,
                    'lotCode' => $line['lotCode'] ?? null,
                    'expiryDate' => $line['expiryDate'] ?? null,
                    'locationId' => $line['locationId'] ?? null,
                ];
            }
            $expected = array_sum(array_column($lines, 'expected')) ?: (int) ($receipt['expected'] ?? 0);
            $received = array_sum(array_column($lines, 'received')) ?: (int) ($receipt['received'] ?? 0);
            $rows[] = [
                'receipt' => $receipt,
                'code' => DocumentCodes::receipt((string) $receipt['id'], $receipt['code'] ?? null),
                'orderCode' => is_array($order)
                    ? DocumentCodes::order((string) $order['id'], $order['code'] ?? null)
                    : strtoupper((string) ($receipt['purchaseOrderId'] ?? '')),
                'requestCode' => $linkedCodes ? implode(' · ', $linkedCodes) : null,
                'warehouseName' => (string) (is_array($warehouse) ? ($warehouse['name'] ?? $warehouseId) : $warehouseId),
                'supplierName' => (string) (is_array($supplier) ? ($supplier['name'] ?? 'Fournisseur') : 'Fournisseur'),
                'supplierPhone' => is_array($supplier) ? ($supplier['phone'] ?? null) : null,
                'expectedDate' => (string) (is_array($order) ? ($order['expectedDate'] ?? $receipt['date'] ?? '') : ($receipt['date'] ?? '')),
                'lines' => $lines,
                'variance' => $received - $expected,
            ];
        }

        usort($rows, function (array $a, array $b) {
            $rank = ['pending' => 0, 'checking' => 1, 'partial' => 2, 'validated' => 3];

            return ($rank[$a['receipt']['status'] ?? ''] ?? 9) <=> ($rank[$b['receipt']['status'] ?? ''] ?? 9)
                ?: strcmp((string) ($b['receipt']['date'] ?? ''), (string) ($a['receipt']['date'] ?? ''));
        });

        return $rows;
    }

    private function scopedRequests(): Collection
    {
        $rows = $this->procurement->list();
        $scope = $this->userWarehouseScope();
        if (! $scope) {
            return $rows->values();
        }

        return $rows->filter(fn (array $r) => in_array($r['warehouseId'] ?? '', $scope, true))->values();
    }

    private function scopedOrders(): Collection
    {
        $rows = $this->purchases->list();
        $scope = $this->userWarehouseScope();
        if (! $scope) {
            return $rows->values();
        }

        return $rows->filter(fn (array $r) => in_array($r['warehouseId'] ?? '', $scope, true))->values();
    }

    private function scopedReceipts(): Collection
    {
        $rows = $this->receipts->list();
        $scope = $this->userWarehouseScope();
        if (! $scope) {
            return $rows->values();
        }

        return $rows->filter(fn (array $r) => in_array($r['warehouseId'] ?? '', $scope, true))->values();
    }

    private function scopedStock(): Collection
    {
        $scope = $this->userWarehouseScope() ?: $this->tenant->companyWarehouseIds();
        if (! $scope) {
            return $this->stock->all();
        }

        return $this->stock->forWarehouses($scope);
    }

    /** @return list<string>|null */
    private function userWarehouseScope(): ?array
    {
        $ids = array_values(array_filter($this->tenant->user()?->warehouse_ids ?? []));

        return $ids ?: null;
    }

    /** @param  Collection<int, array<string, mixed>>  $orders */
    private function inTransit(Collection $orders, string $productId, string $warehouseId): int
    {
        $sum = 0;
        foreach ($orders as $order) {
            if (($order['warehouseId'] ?? '') !== $warehouseId) {
                continue;
            }
            if (! in_array($order['status'] ?? '', self::OPEN_ORDERS, true)) {
                continue;
            }
            foreach ($order['items'] ?? [] as $line) {
                if (($line['productId'] ?? '') === $productId) {
                    $sum += (int) ($line['quantity'] ?? 0);
                }
            }
        }

        return $sum;
    }

    /** @param  Collection<int, array<string, mixed>>  $orders */
    private function orderForRequest(Collection $orders, string $requestId): ?array
    {
        foreach ($orders as $order) {
            if (in_array($requestId, $this->orderRequestIds($order), true)) {
                return $order;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $order
     *  @return list<string>
     */
    private function orderRequestIds(array $order): array
    {
        if (! empty($order['procurementRequestIds']) && is_array($order['procurementRequestIds'])) {
            return array_values(array_map('strval', $order['procurementRequestIds']));
        }
        if (! empty($order['procurementRequestId'])) {
            return [(string) $order['procurementRequestId']];
        }

        return [];
    }

    private function normalizeStatus(string $status): string
    {
        return $status === 'cancelled' ? 'rejected' : $status;
    }

    /** @param  array<string, mixed>  $row */
    private function isReadyToPurchase(array $row, ?string $userId): bool
    {
        $request = $row['request'] ?? [];
        if (($request['status'] ?? '') !== 'approved') {
            return false;
        }
        if (empty($request['takenAt'])) {
            return false;
        }
        if (($request['assignedRole'] ?? '') !== 'PROCUREMENT') {
            return false;
        }
        if (($row['decision'] ?? '') === 'issue') {
            return false;
        }
        if (! empty($row['linkedOrder'])) {
            return false;
        }
        if ($userId && ($request['assigneeId'] ?? null) !== $userId) {
            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $request */
    private function nextOwnerLabel(array $request, string $decision): string
    {
        $status = $this->normalizeStatus((string) ($request['status'] ?? ''));
        if (in_array($status, ['rejected', 'closed', 'expired'], true)) {
            return '';
        }
        if ($status === 'ordered') {
            return 'Magasin';
        }
        if (($request['assignedRole'] ?? '') === 'WAREHOUSE_MANAGER') {
            return 'Magasin';
        }
        if (($request['assignedRole'] ?? '') === 'PROCUREMENT') {
            return 'Achats';
        }
        if (in_array($status, ['submitted', 'in_review'], true)) {
            return $decision === 'issue' ? 'Magasin' : 'Achats';
        }

        return $decision === 'issue' ? 'Magasin' : 'Achats';
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $order
     * @param  array<string, mixed>|null  $receipt
     * @param  array<string, mixed>|null  $consultation
     * @return list<array<string, mixed>>
     */
    private function requestTimeline(array $request, ?array $order, ?array $receipt, string $decision, ?string $assigneeName, ?array $consultation): array
    {
        $status = $this->normalizeStatus((string) ($request['status'] ?? ''));
        $rejected = $status === 'rejected';
        $expired = $status === 'expired';
        $stopped = $rejected || $expired;
        $toStore = ($request['assignedRole'] ?? '') === 'WAREHOUSE_MANAGER' || ($decision === 'issue' && empty($request['assignedRole']));
        $pastSubmit = $status !== 'draft';
        $pastReview = in_array($status, ['approved', 'ordered', 'closed', 'rejected'], true) || ! empty($request['decidedAt']);
        $pastSupply = in_array($status, ['ordered', 'closed'], true);

        $consultState = 'upcoming';
        if ($stopped || $toStore) {
            $consultState = 'skipped';
        } elseif (($consultation['status'] ?? '') === 'awarded') {
            $consultState = 'done';
        } elseif ($order && ! $consultation) {
            $consultState = 'skipped';
        } elseif ($consultation) {
            $consultState = 'current';
        }

        return [
            ['id' => 'created', 'label' => 'Créée', 'state' => 'done', 'date' => $request['createdAt'] ?? null],
            [
                'id' => 'submitted',
                'label' => 'Soumise',
                'state' => $pastSubmit ? 'done' : ($status === 'draft' ? 'upcoming' : 'current'),
                'date' => $request['submittedAt'] ?? null,
            ],
            [
                'id' => 'review',
                'label' => $rejected ? 'Rejetée' : ($expired ? 'Périmée' : 'Validée'),
                'state' => $stopped ? 'current' : ($pastReview ? 'done' : ($status === 'in_review' ? 'current' : 'upcoming')),
                'date' => $request['decidedAt'] ?? null,
                'detail' => $request['decisionNote'] ?? null,
            ],
            [
                'id' => 'stock',
                'label' => 'Analyse stock',
                'state' => $stopped ? 'skipped' : ($pastReview ? 'done' : 'upcoming'),
            ],
            [
                'id' => 'supply',
                'label' => $toStore ? 'Sortie de stock' : 'Approvisionnement',
                'state' => $stopped ? 'skipped' : ($pastSupply || ! empty($request['takenAt']) ? 'done' : ($status === 'approved' ? 'current' : 'upcoming')),
                'date' => $request['handedOffAt'] ?? $request['takenAt'] ?? null,
                'detail' => $assigneeName
                    ? (! empty($request['takenAt']) ? $assigneeName.' a pris en charge' : 'Transmise à '.$assigneeName)
                    : null,
            ],
            [
                'id' => 'consultation',
                'label' => 'Consultation',
                'state' => $consultState,
                'date' => $consultation['quotedAt'] ?? $consultation['sentAt'] ?? null,
            ],
            [
                'id' => 'order',
                'label' => 'Commande',
                'state' => $stopped || $toStore ? 'skipped' : ($order ? 'done' : (! empty($request['takenAt']) || $pastSupply ? 'current' : 'upcoming')),
                'date' => $order['createdAt'] ?? null,
                'detail' => $order ? DocumentCodes::order((string) $order['id'], $order['code'] ?? null) : null,
            ],
            [
                'id' => 'receipt',
                'label' => 'Réception',
                'state' => $stopped || $toStore ? 'skipped' : (($receipt['status'] ?? '') === 'validated' ? 'done' : ($order ? 'current' : 'upcoming')),
                'date' => $receipt['date'] ?? null,
                'detail' => $receipt ? DocumentCodes::receipt((string) $receipt['id'], $receipt['code'] ?? null) : ($order ? 'En attente magasin' : null),
            ],
        ];
    }

    /** @param  array<string, mixed>  $order */
    private function isOrderLate(array $order, string $today): bool
    {
        $status = (string) ($order['status'] ?? '');
        if (in_array($status, ['received', 'cancelled'], true)) {
            return false;
        }
        if ($status === 'delayed') {
            return true;
        }

        return in_array($status, ['sent', 'confirmed', 'partial', 'draft'], true)
            && (string) ($order['expectedDate'] ?? '') < $today;
    }

    /** @param  array<string, mixed>  $order
     *  @param  array<string, mixed>|null  $receipt
     */
    private function orderNextAction(array $order, bool $late, ?array $receipt): string
    {
        $status = (string) ($order['status'] ?? '');
        if ($status === 'cancelled') {
            return 'Annulée';
        }
        if ($status === 'received') {
            return 'Clôturée';
        }
        if ($receipt && ($receipt['status'] ?? '') !== 'validated') {
            return 'À réceptionner';
        }
        if ($late) {
            return 'Livraison en retard';
        }
        if ($status === 'sent') {
            return 'En attente confirmation';
        }
        if ($status === 'partial') {
            return 'Réception partielle';
        }

        return 'En transit';
    }

    /** @param  array<string, mixed>  $order
     *  @param  array<string, mixed>|null  $receipt
     *  @return list<array<string, mixed>>
     */
    private function orderTimeline(array $order, ?array $receipt, bool $late): array
    {
        $closed = ($order['status'] ?? '') === 'received' || ($receipt['status'] ?? '') === 'validated';
        $cancelled = ($order['status'] ?? '') === 'cancelled';
        $sent = ($order['status'] ?? '') !== 'draft';
        $confirmed = in_array($order['status'] ?? '', ['confirmed', 'partial', 'received', 'delayed'], true) || $closed;

        return [
            ['id' => 'created', 'label' => 'Créée', 'state' => 'done', 'date' => $order['createdAt'] ?? null],
            [
                'id' => 'sent',
                'label' => 'Envoyée au fournisseur',
                'state' => $cancelled ? 'skipped' : ($sent ? 'done' : 'current'),
                'date' => $sent ? ($order['createdAt'] ?? null) : null,
            ],
            [
                'id' => 'confirmed',
                'label' => $late && ! $closed ? 'En retard' : 'Confirmée',
                'state' => $cancelled ? 'skipped' : ($confirmed ? 'done' : ($sent ? 'current' : 'upcoming')),
            ],
            [
                'id' => 'receipt',
                'label' => 'Réception',
                'state' => $cancelled ? 'skipped' : ($closed ? 'done' : ($receipt ? 'current' : 'upcoming')),
                'date' => $receipt['checkedAt'] ?? $receipt['date'] ?? null,
                'detail' => $receipt ? DocumentCodes::receipt((string) $receipt['id'], $receipt['code'] ?? null) : null,
            ],
        ];
    }

    /** @param  array<string, mixed>  $order */
    private function orderEtaLabel(array $order, bool $late, string $today): string
    {
        $status = (string) ($order['status'] ?? '');
        if ($status === 'received') {
            return 'Reçue';
        }
        if ($status === 'cancelled') {
            return 'Annulée';
        }
        $expected = (string) ($order['expectedDate'] ?? '');
        if ($expected === '') {
            return '—';
        }
        $diff = (int) round((strtotime($expected.' 00:00:00') - strtotime($today.' 00:00:00')) / 86400);
        if ($late || $diff < 0) {
            return 'Retard '.abs($diff).' j';
        }
        if ($diff === 0) {
            return 'Aujourd’hui';
        }

        return 'Dans '.$diff.' j';
    }
}
