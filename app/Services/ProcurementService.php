<?php

namespace App\Services;

use App\Repositories\Eloquent\ProcurementRequestRepository;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function __construct(
        private readonly ProcurementRequestRepository $requests,
        private readonly UserDirectory $users,
        private readonly OpsNotifier $notifier,
    ) {}

    public function list(): Collection
    {
        return $this->requests->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $seq = 100 + $this->requests->all()->count() + 1;
            $now = now()->toIso8601String();
            $status = $input['status'] ?? 'submitted';
            $digits = substr(preg_replace('/\D/', '', (string) $seq) ?: '0', -5);
            $request = $this->requests->put([
                'id' => Uid::make('pr'),
                'code' => $input['code'] ?? 'DA-2026-'.str_pad($digits, 5, '0', STR_PAD_LEFT),
                'createdAt' => $now,
                'submittedAt' => $status === 'draft' ? null : $now,
                'status' => $status,
                'requesterId' => $input['requesterId'],
                'warehouseId' => $input['warehouseId'],
                'items' => $input['items'] ?? [],
                'note' => $input['note'] ?? '',
                'priority' => $input['priority'] ?? 'medium',
                'service' => $input['service'] ?? null,
            ]);
            if ($status !== 'draft') {
                $this->notifier->alert([
                    'title' => $request['code'] ?? 'Nouvelle demande',
                    'message' => $request['note'] ?: 'Une demande d’achat a été créée.',
                    'severity' => ($request['priority'] ?? '') === 'high' ? 'warning' : 'info',
                    'domain' => 'procurement',
                    'relatedId' => $request['id'],
                    'warehouseId' => $request['warehouseId'],
                    'roles' => ['MANAGER'],
                ]);
                $this->notifier->notify('Nouvelle demande d’achat', $request['note'] ?: ($request['code'].' a été soumise.'));
            }

            return $request;
        });
    }

    public function updateStatus(string $id, string $status, array $extras = []): array
    {
        return DB::transaction(function () use ($id, $status, $extras) {
            $request = $this->requests->findOrFail($id, 'Demande introuvable');
            $request['status'] = $status;
            $request = array_merge($request, $extras);
            if ($status === 'submitted' && empty($request['submittedAt'])) {
                $request['submittedAt'] = $extras['submittedAt'] ?? now()->toIso8601String();
            }
            if (in_array($status, ['approved', 'rejected'], true) && empty($request['decidedAt'])) {
                $request['decidedAt'] = $extras['decidedAt'] ?? now()->toIso8601String();
            }
            $this->requests->put($request);
            if ($status === 'approved' && ! empty($extras['assigneeId']) && ! empty($extras['handedOffAt'])) {
                $assignee = $this->users->find($extras['assigneeId']);
                $toBuyer = ($extras['assignedRole'] ?? '') === 'PROCUREMENT';
                $code = $request['code'] ?? strtoupper($request['id']);
                $this->notifier->alert([
                    'title' => $code.' validée',
                    'message' => $toBuyer
                        ? 'Transmise à '.($assignee['firstName'] ?? 'l’acheteur').' pour approvisionnement.'
                        : 'Transmise à '.($assignee['firstName'] ?? 'le magasin').' pour sortie de stock.',
                    'severity' => 'info',
                    'domain' => 'procurement',
                    'relatedId' => $request['id'],
                    'warehouseId' => $request['warehouseId'] ?? null,
                    'userId' => $extras['assigneeId'],
                    'roles' => $toBuyer ? ['PROCUREMENT'] : ['WAREHOUSE_MANAGER'],
                ]);
                $this->notifier->notify(
                    $toBuyer ? 'Nouvelle DA à traiter' : 'Sortie de stock à faire',
                    $code.' vient d’être validée.',
                );
            }

            return $request;
        });
    }
}
