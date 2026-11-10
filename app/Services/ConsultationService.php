<?php

namespace App\Services;

use App\Repositories\Eloquent\ConsultationRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsultationService
{
    public function __construct(
        private readonly ConsultationRepository $consultations,
        private readonly OpsNotifier $notifier,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->consultations->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->consultations->all();
    }

    public function create(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $seq = $this->consultations->countAll() + 12;
            $createdAt = now()->toIso8601String();
            $consultation = $this->consultations->put([
                'id' => Uid::make('cs'),
                'createdAt' => $createdAt,
                ...$input,
                'code' => $input['code'] ?? 'CS-2026-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            ]);
            $this->notifier->notify(
                'Consultation envoyée',
                ($consultation['code'] ?? 'CS').' — '.count($consultation['supplierIds'] ?? []).' fournisseurs sollicités.',
            );

            return $consultation;
        });
    }

    public function update(string $id, array $input): array
    {
        $this->consultations->findOrFail($id, 'Consultation introuvable');

        return $this->consultations->merge($id, $input, 'Consultation introuvable');
    }
}
