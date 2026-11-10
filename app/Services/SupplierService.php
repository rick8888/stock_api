<?php

namespace App\Services;

use App\Repositories\Eloquent\SupplierRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;

class SupplierService
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->suppliers->all();
        }

        return $this->suppliers->forCompany($this->tenant->companyId());
    }

    public function get(string $id): ?array
    {
        $supplier = $this->suppliers->find($id);
        if (! $supplier) {
            return null;
        }
        if (! $this->tenant->bypassTenantFilter()) {
            $companyId = $this->tenant->companyId();
            if (! empty($supplier['companyId']) && $supplier['companyId'] !== $companyId) {
                return null;
            }
        }

        return $supplier;
    }

    public function create(array $input): array
    {
        return $this->suppliers->put($this->tenant->stamp([
            'id' => Uid::make('sup'),
            'reliability' => 80,
            'status' => 'active',
            ...$input,
        ]));
    }

    public function update(string $id, array $input): array
    {
        $this->suppliers->findOrFail($id, 'Fournisseur introuvable');

        return $this->suppliers->merge($id, $input, 'Fournisseur introuvable');
    }
}
