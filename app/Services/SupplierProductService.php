<?php

namespace App\Services;

use App\Repositories\Eloquent\SupplierProductRepository;
use App\Support\Tenant;
use Illuminate\Support\Collection;

/**
 * Lecture du catalogue fournisseur-article : prix, MOQ, colisage, délai.
 */
class SupplierProductService
{
    public function __construct(
        private readonly SupplierProductRepository $catalog,
        private readonly Tenant $tenant,
    ) {}

    public function list(): Collection
    {
        if ($this->tenant->bypassTenantFilter()) {
            return $this->catalog->all();
        }

        return $this->catalog->forCompany($this->tenant->companyId());
    }
}
