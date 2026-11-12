<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\ConsultationRepository;
use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use Illuminate\Support\Facades\DB;

/**
 * OA créée depuis une offre sourcing — orchestration serveur (DA → OA → BR).
 */
class PurchaseFromOfferService
{
    public function __construct(
        private readonly ProcurementSourcingService $sourcing,
        private readonly PurchaseService $purchases,
        private readonly PurchaseOrderRepository $orders,
        private readonly ProcurementRequestRepository $requests,
        private readonly ConsultationRepository $consultations,
    ) {}

    /**
     * @param  list<string>  $requestIds
     * @return array<string, mixed>
     */
    public function create(array $requestIds, string $supplierId): array
    {
        $draft = $this->sourcing->draftForSupplier($requestIds, $supplierId);
        $ids = $draft['requestIds'];

        return DB::transaction(function () use ($draft, $ids, $supplierId) {
            foreach ($this->orders->all() as $order) {
                $linked = $order['procurementRequestIds'] ?? [];
                if (! is_array($linked) || ! $linked) {
                    if (! empty($order['procurementRequestId'])) {
                        $linked = [$order['procurementRequestId']];
                    }
                }
                foreach ($linked as $linkedId) {
                    if (in_array((string) $linkedId, $ids, true)) {
                        throw new DomainException('Une commande existe déjà pour l’une de ces demandes');
                    }
                }
            }

            foreach ($this->consultations->all() as $consultation) {
                $status = (string) ($consultation['status'] ?? '');
                if (in_array($status, ['awarded', 'cancelled'], true)) {
                    continue;
                }
                $linked = $consultation['procurementRequestIds'] ?? [];
                if (! is_array($linked)) {
                    continue;
                }
                foreach ($linked as $linkedId) {
                    if (in_array((string) $linkedId, $ids, true)) {
                        $this->consultations->merge($consultation['id'], ['status' => 'cancelled'], 'Consultation introuvable');
                        break;
                    }
                }
            }

            $order = $this->purchases->create([
                'supplierId' => $supplierId,
                'warehouseId' => $draft['warehouseId'],
                'status' => 'sent',
                'items' => $draft['items'],
                'expectedDate' => now()->addDays(max(1, $draft['leadTimeDays']))->toDateString(),
                'amount' => (int) round($draft['amount']),
                'procurementRequestId' => $ids[0],
                'procurementRequestIds' => $ids,
            ]);

            foreach ($ids as $id) {
                $this->requests->merge($id, ['status' => 'ordered'], 'Demande introuvable');
            }

            return $order;
        });
    }
}
