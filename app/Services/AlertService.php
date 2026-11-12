<?php

namespace App\Services;

use App\Repositories\Eloquent\AlertRepository;
use App\Support\AlertVisibility;
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
        $base = $this->tenant->bypassTenantFilter()
            ? $this->alerts->all()
            : $this->alerts->forWarehouses($this->tenant->companyWarehouseIds());

        $role = $this->tenant->role();
        $userId = $this->tenant->user()?->id;
        $warehouses = array_values(array_filter($this->tenant->user()?->warehouse_ids ?? []));

        return $base
            ->filter(fn (array $alert) => AlertVisibility::visibleTo($alert, $role, $warehouses, $userId))
            ->values();
    }

    public function markRead(string $id): void
    {
        $this->alerts->merge($id, ['read' => true], 'Alerte introuvable');
    }
}
