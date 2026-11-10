<?php

namespace App\Support;

/**
 * Matrice RBAC alignée sur aria/src/mocks/roles.ts
 */
class RolePermissions
{
    /** @return list<string> */
    public static function for(string $role): array
    {
        return match ($role) {
            'SUPER_ADMIN' => [
                'platform.view', 'platform.companies', 'platform.plans', 'platform.billing',
            ],
            'TENANT_OWNER' => [
                'account.view', 'account.company', 'account.admins', 'account.billing',
            ],
            'ADMIN' => self::allExcept(['finance.payment', 'platform.view', 'platform.companies', 'platform.plans', 'platform.billing', 'account.view', 'account.company', 'account.admins', 'account.billing']),
            'MANAGER' => [
                'product.view', 'warehouse.view', 'stock.view', 'stock.inventory',
                'procurement.view', 'procurement.create', 'procurement.approve',
                'purchase.view', 'purchase.create', 'delivery.view',
                'analytics.view', 'task.view', 'task.create', 'task.update',
                'assistant.use', 'finance.view',
            ],
            'WAREHOUSE_MANAGER' => [
                'product.view', 'warehouse.view',
                'stock.view', 'stock.receive', 'stock.issue', 'stock.transfer', 'stock.adjust', 'stock.inventory',
                'procurement.view', 'procurement.create', 'purchase.view',
                'delivery.view', 'delivery.prepare',
                'task.view', 'task.create', 'task.update', 'assistant.use',
            ],
            'PROCUREMENT' => [
                'product.view', 'product.create', 'product.update',
                'stock.view', 'warehouse.view',
                'procurement.view', 'procurement.create',
                'purchase.view', 'purchase.create', 'purchase.update',
                'task.view', 'task.create', 'task.update', 'assistant.use',
            ],
            'FINANCE' => [
                'purchase.view', 'procurement.view',
                'finance.view', 'finance.invoice', 'finance.payment', 'finance.budget',
                'analytics.view', 'task.view', 'task.create', 'assistant.use',
            ],
            'DISPATCHER' => [
                'delivery.view', 'delivery.create', 'delivery.update',
                'stock.view', 'warehouse.view',
                'task.view', 'task.create', 'task.update', 'assistant.use',
            ],
            'DRIVER' => [
                'delivery.view', 'delivery.confirm', 'task.view', 'assistant.use',
            ],
            'AUDITOR' => array_values(array_filter(self::all(), fn (string $key) => str_ends_with($key, '.view') || $key === 'assistant.use')),
            default => [],
        };
    }

    public static function allows(string $role, string $permission): bool
    {
        return in_array($permission, self::for($role), true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'product.view', 'product.create', 'product.update', 'product.archive',
            'warehouse.view', 'warehouse.create', 'warehouse.update', 'warehouse.assign',
            'stock.view', 'stock.receive', 'stock.issue', 'stock.transfer', 'stock.adjust', 'stock.inventory',
            'procurement.view', 'procurement.create', 'procurement.approve',
            'purchase.view', 'purchase.create', 'purchase.update', 'purchase.approve',
            'delivery.view', 'delivery.create', 'delivery.update', 'delivery.confirm', 'delivery.prepare',
            'finance.view', 'finance.invoice', 'finance.payment', 'finance.budget',
            'user.view', 'user.create', 'user.update', 'user.assign_role',
            'task.view', 'task.create', 'task.update',
            'analytics.view', 'assistant.use',
            'platform.view', 'platform.companies', 'platform.plans', 'platform.billing',
            'account.view', 'account.company', 'account.admins', 'account.billing',
        ];
    }

    /** @param list<string> $exclude */
    private static function allExcept(array $exclude): array
    {
        return array_values(array_filter(self::all(), fn (string $key) => ! in_array($key, $exclude, true)));
    }
}
