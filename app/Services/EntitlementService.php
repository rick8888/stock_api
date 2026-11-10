<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Company;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Support\Tenant;

class EntitlementService
{
    public const MODULES = [
        'stock',
        'procurement',
        'purchasing',
        'logistics',
        'assistant',
        'analytics',
        'finance',
    ];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionPlanRepository $plans,
        private readonly Tenant $tenant,
    ) {}

    public function snapshot(?string $companyId = null): array
    {
        if ($this->tenant->isPlatform() && ! $companyId) {
            return [
                'companyId' => null,
                'planId' => null,
                'planName' => 'Plateforme',
                'status' => 'active',
                'modules' => self::MODULES,
                'maxUsers' => 0,
                'maxWarehouses' => 0,
                'usersCount' => 0,
                'warehousesCount' => 0,
                'seats' => 0,
                'unlimitedUsers' => true,
                'unlimitedWarehouses' => true,
                'canAddUser' => true,
                'canAddWarehouse' => true,
            ];
        }

        $companyId = $companyId ?: $this->tenant->companyId();
        if (! $companyId) {
            throw new DomainException('Entreprise introuvable.', 404);
        }
        $this->tenant->assertCompanyAccess($companyId);
        $company = Company::query()->find($companyId);
        $subscription = $this->subscriptions->forCompany($companyId);
        $plan = $subscription ? $this->plans->find($subscription['planId'] ?? '') : null;
        $modules = array_values(array_intersect(self::MODULES, $plan['modules'] ?? self::MODULES));
        $maxUsers = (int) ($plan['maxUsers'] ?? 0);
        $maxWarehouses = (int) ($plan['maxWarehouses'] ?? 0);
        $usersCount = (int) ($company?->users_count ?? 0);
        $warehousesCount = (int) ($company?->warehouses_count ?? 0);
        $status = (string) ($subscription['status'] ?? 'active');
        $trialExpired = $status === 'trialing'
            && ! empty($subscription['trialEndsAt'])
            && $subscription['trialEndsAt'] < now()->toDateString();
        if ($trialExpired) {
            $status = 'canceled';
        }
        $blocked = in_array($status, ['canceled', 'paused'], true)
            || ($company && in_array($company->status, ['suspended', 'churned'], true));

        return [
            'companyId' => $companyId,
            'planId' => $plan['id'] ?? null,
            'planName' => $plan['name'] ?? 'Sans plan',
            'status' => $status,
            'modules' => $blocked ? [] : $modules,
            'maxUsers' => $maxUsers,
            'maxWarehouses' => $maxWarehouses,
            'usersCount' => $usersCount,
            'warehousesCount' => $warehousesCount,
            'seats' => (int) ($subscription['seats'] ?? $usersCount),
            'unlimitedUsers' => $maxUsers === 0,
            'unlimitedWarehouses' => $maxWarehouses === 0,
            'canAddUser' => ! $blocked && ($maxUsers === 0 || $usersCount < $maxUsers),
            'canAddWarehouse' => ! $blocked && ($maxWarehouses === 0 || $warehousesCount < $maxWarehouses),
        ];
    }

    public function hasModule(string $module, ?string $companyId = null): bool
    {
        if ($this->tenant->isPlatform()) {
            return true;
        }
        $snapshot = $this->snapshot($companyId);

        return in_array($module, $snapshot['modules'], true);
    }

    public function assertModule(string $module, ?string $companyId = null): void
    {
        if ($this->hasModule($module, $companyId)) {
            return;
        }
        throw new DomainException("Module « {$module} » non inclus dans votre plan.", 403);
    }

    public function assertCanAddUser(?string $companyId = null): void
    {
        $snapshot = $this->snapshot($companyId);
        if ($snapshot['canAddUser']) {
            return;
        }
        $max = $snapshot['maxUsers'];
        throw new DomainException(
            $max > 0
                ? "Limite du plan atteinte ({$snapshot['usersCount']}/{$max} utilisateurs)."
                : 'Ajout d’utilisateur impossible avec cet abonnement.',
            422,
        );
    }

    public function assertCanAddWarehouse(?string $companyId = null): void
    {
        $snapshot = $this->snapshot($companyId);
        if ($snapshot['canAddWarehouse']) {
            return;
        }
        $max = $snapshot['maxWarehouses'];
        throw new DomainException(
            $max > 0
                ? "Limite du plan atteinte ({$snapshot['warehousesCount']}/{$max} entrepôts)."
                : 'Ajout d’entrepôt impossible avec cet abonnement.',
            422,
        );
    }
}
