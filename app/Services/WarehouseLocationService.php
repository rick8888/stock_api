<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Repositories\Eloquent\WarehouseLocationRepository;
use App\Support\Tenant;
use App\Support\Uid;
use Illuminate\Support\Collection;

class WarehouseLocationService
{
    public function __construct(
        private readonly WarehouseLocationRepository $locations,
        private readonly Tenant $tenant,
    ) {}

    public function list(?string $warehouseId = null): Collection
    {
        if ($warehouseId) {
            $this->tenant->assertWarehouseAccess($warehouseId);

            return $this->locations->forWarehouse($warehouseId);
        }

        if (! $this->tenant->bypassTenantFilter()) {
            return $this->locations->forWarehouses($this->tenant->companyWarehouseIds())
                ->sortBy(fn (array $row) => $row['code'] ?? '')
                ->values();
        }

        return $this->locations->all()
            ->sortBy(fn (array $row) => $row['code'] ?? '')
            ->values();
    }

    public function get(string $id): ?array
    {
        $location = $this->locations->find($id);
        if (! $location) {
            return null;
        }
        $this->tenant->assertWarehouseAccess((string) ($location['warehouseId'] ?? ''));

        return $location;
    }

    public function create(array $input): array
    {
        $warehouseId = trim((string) ($input['warehouseId'] ?? ''));
        if ($warehouseId === '') {
            throw new DomainException('Entrepôt requis.');
        }
        $this->tenant->assertWarehouseAccess($warehouseId);
        $aisle = strtoupper(trim((string) ($input['aisle'] ?? '')));
        $rack = strtoupper(trim((string) ($input['rack'] ?? '')));
        $bin = strtoupper(trim((string) ($input['bin'] ?? '')));
        if ($aisle === '' || $rack === '' || $bin === '') {
            throw new DomainException('Allée, rack et bac sont requis.');
        }
        $code = trim((string) ($input['code'] ?? '')) ?: "{$aisle}-{$rack}-{$bin}";
        if ($this->locations->findByCode($warehouseId, $code)) {
            throw new DomainException("L’emplacement {$code} existe déjà.");
        }
        $type = $input['type'] ?? 'pick';
        if (! in_array($type, ['pick', 'bulk', 'staging'], true)) {
            $type = 'pick';
        }

        return $this->locations->put($this->tenant->stamp([
            'id' => Uid::make('loc'),
            'warehouseId' => $warehouseId,
            'code' => $code,
            'aisle' => $aisle,
            'rack' => $rack,
            'bin' => $bin,
            'type' => $type,
            'active' => (bool) ($input['active'] ?? true),
        ]));
    }

    public function update(string $id, array $input): array
    {
        $location = $this->get($id);
        if (! $location) {
            throw new DomainException('Emplacement introuvable', 404);
        }
        foreach (['aisle', 'rack', 'bin'] as $key) {
            if (array_key_exists($key, $input)) {
                $location[$key] = strtoupper(trim((string) $input[$key]));
            }
        }
        if (array_key_exists('type', $input) && in_array($input['type'], ['pick', 'bulk', 'staging'], true)) {
            $location['type'] = $input['type'];
        }
        if (array_key_exists('active', $input)) {
            $location['active'] = (bool) $input['active'];
        }
        if (array_key_exists('code', $input) && trim((string) $input['code']) !== '') {
            $location['code'] = trim((string) $input['code']);
        } else {
            $location['code'] = "{$location['aisle']}-{$location['rack']}-{$location['bin']}";
        }

        return $this->locations->put($location);
    }
}
