<?php

namespace App\Services;

use App\Repositories\Eloquent\AlertRepository;
use App\Support\Tenant;
use Illuminate\Support\Collection;

class AlertService
{
    public function __construct(
        private readonly AlertRepository $alerts,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        if (! $this->tenant->bypassTenantFilter()) {
            return $this->alerts->forWarehouses($this->tenant->companyWarehouseIds());
        }

        return $this->alerts->all();
    }

    public function markRead(string $id): void
    {
        $this->alerts->merge($id, ['read' => true], 'Alerte introuvable');
    }
}
