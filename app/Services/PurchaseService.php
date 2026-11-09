<?php

namespace App\Services;

use App\Repositories\Eloquent\PurchaseOrderRepository;
use App\Repositories\Eloquent\SupplierRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly SupplierRepository $suppliers,
        private readonly OpsNotifier $notifier,
    ) {}

    public function list(): Collection
    {
        return $this->orders->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $seq = 1030 + $this->orders->all()->count() + 1;
            $createdAt = now()->toIso8601String();
            $order = $this->orders->put([
                'id' => Uid::make('po'),
                'createdAt' => $createdAt,
                ...$input,
                'code' => $input['code'] ?? 'OA-2026-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            ]);
            $supplier = $this->suppliers->find($order['supplierId'] ?? '');
            $grouped = count($order['procurementRequestIds'] ?? []) > 1;
            $this->notifier->alert([
                'title' => ($order['code'] ?? 'OA').' envoyée',
                'message' => $grouped
                    ? count($order['procurementRequestIds']).' demandes regroupées — transmise à '.($supplier['name'] ?? 'le fournisseur').'.'
                    : 'Commande transmise à '.($supplier['name'] ?? 'le fournisseur').'.',
                'severity' => 'info',
                'domain' => 'procurement',
                'relatedId' => $order['id'],
                'warehouseId' => $order['warehouseId'] ?? null,
                'roles' => ['PROCUREMENT', 'WAREHOUSE_MANAGER', 'MANAGER'],
            ]);
            $this->notifier->notify('Ordre d’achat créé', ($order['code'] ?? 'OA').' — '.($supplier['name'] ?? 'fournisseur').'.');

            return $order;
        });
    }

    public function updateStatus(string $id, string $status): array
    {
        $this->orders->findOrFail($id, 'Commande introuvable');

        return $this->orders->merge($id, ['status' => $status], 'Commande introuvable');
    }
}
