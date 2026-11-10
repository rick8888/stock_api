<?php

namespace App\Support;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Repositories\Eloquent\StockItemRepository;
use App\Repositories\Eloquent\WarehouseRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class Tenant
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly StockItemRepository $stock,
    ) {}

    public function user(): ?User
    {
        /** @var User|null $user */
        $user = Auth::guard('api')->user();

        return $user;
    }

    public function actorId(): string
    {
        $user = $this->user();
        if (! $user) {
            throw new DomainException('Non authentifié.', 401);
        }

        return (string) $user->id;
    }

    public function companyId(): ?string
    {
        return $this->user()?->company_id;
    }

    public function role(): string
    {
        return (string) ($this->user()?->role ?? '');
    }

    public function isPlatform(): bool
    {
        return $this->role() === 'SUPER_ADMIN';
    }

    public function bypassTenantFilter(): bool
    {
        return $this->isPlatform();
    }

    /** @return list<string> */
    public function companyWarehouseIds(): array
    {
        if ($this->bypassTenantFilter()) {
            return $this->warehouses->all()->pluck('id')->values()->all();
        }

        $companyId = $this->companyId();
        if (! $companyId) {
            return [];
        }

        $ids = $this->warehouses->idsForCompany($companyId);
        if ($ids) {
            return $ids;
        }

        // Fallback legacy payload-only rows.
        return $this->warehouses->all()
            ->filter(fn (array $warehouse) => ($warehouse['companyId'] ?? null) === $companyId)
            ->pluck('id')
            ->values()
            ->all();
    }

    public function assertWarehouseAccess(string $warehouseId): void
    {
        if ($this->bypassTenantFilter()) {
            return;
        }

        if (! in_array($warehouseId, $this->companyWarehouseIds(), true)) {
            throw new DomainException('Entrepôt hors périmètre de l’entreprise.', 403);
        }

        $scoped = $this->user()?->warehouse_ids ?? [];
        if ($scoped && ! in_array($warehouseId, $scoped, true)) {
            throw new DomainException('Entrepôt non autorisé pour cet utilisateur.', 403);
        }
    }

    public function assertCompanyAccess(?string $companyId): void
    {
        if ($this->bypassTenantFilter()) {
            return;
        }

        if (! $companyId || $companyId !== $this->companyId()) {
            throw new DomainException('Ressource hors périmètre de l’entreprise.', 403);
        }
    }

    public function filterWarehouses(Collection $items): Collection
    {
        if ($this->bypassTenantFilter()) {
            return $items->values();
        }

        $ids = array_flip($this->companyWarehouseIds());

        return $items->filter(fn (array $row) => isset($ids[$row['id'] ?? '']))->values();
    }

    public function filterByWarehouseKeys(Collection $items, array $keys = ['warehouseId', 'fromWarehouseId', 'toWarehouseId', 'originWarehouseId', 'destinationWarehouseId']): Collection
    {
        if ($this->bypassTenantFilter()) {
            return $items->values();
        }

        $ids = array_flip($this->companyWarehouseIds());

        return $items->filter(function (array $row) use ($keys, $ids) {
            if (array_key_exists('companyId', $row)) {
                return ($row['companyId'] ?? null) === $this->companyId();
            }
            $hasWarehouseRef = false;
            foreach ($keys as $key) {
                if (empty($row[$key])) {
                    continue;
                }
                $hasWarehouseRef = true;
                if (isset($ids[$row[$key]])) {
                    return true;
                }
            }

            // Pas de lien entrepôt (ex. alerte globale) : visible dans le tenant courant.
            return ! $hasWarehouseRef;
        })->values();
    }

    public function filterUsers(Collection $users): Collection
    {
        if ($this->bypassTenantFilter()) {
            return $users->values();
        }

        $companyId = $this->companyId();

        return $users->filter(fn (array $user) => ($user['companyId'] ?? null) === $companyId)->values();
    }

    public function filterProducts(Collection $products): Collection
    {
        if ($this->bypassTenantFilter()) {
            return $products->values();
        }

        $companyId = $this->companyId();
        $warehouseIds = $this->companyWarehouseIds();
        $linkedProductIds = array_flip($this->stock->productIdsInWarehouses($warehouseIds));

        return $products->filter(function (array $product) use ($companyId, $linkedProductIds) {
            if (($product['companyId'] ?? null) === $companyId) {
                return true;
            }

            return empty($product['companyId']) && isset($linkedProductIds[$product['id'] ?? '']);
        })->values();
    }

    public function stamp(array $doc): array
    {
        $companyId = $this->companyId();
        if ($companyId && empty($doc['companyId'])) {
            $doc['companyId'] = $companyId;
        }

        return $doc;
    }
}
