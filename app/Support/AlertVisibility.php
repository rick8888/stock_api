<?php

namespace App\Support;

/**
 * Visibilité des alertes par rôle / entrepôt (aligné sur aria alert.visibility.ts).
 */
final class AlertVisibility
{
    /** @var array<string, list<string>> */
    private const DOMAIN_PERMISSION = [
        'stock' => ['stock.view'],
        'procurement' => ['procurement.view', 'purchase.view'],
        'logistics' => ['delivery.view'],
        'finance' => ['finance.view'],
        'task' => ['task.view'],
    ];

    private const OVERSIGHT = ['SUPER_ADMIN', 'ADMIN', 'AUDITOR'];

    public static function visibleTo(array $alert, string $role, array $warehouseIds, ?string $userId): bool
    {
        $domain = (string) ($alert['domain'] ?? 'task');
        $needed = self::DOMAIN_PERMISSION[$domain] ?? ['task.view'];
        $allowed = false;
        foreach ($needed as $permission) {
            if (RolePermissions::allows($role, $permission)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            return false;
        }

        $alertWarehouse = $alert['warehouseId'] ?? null;
        if ($alertWarehouse && $warehouseIds && ! in_array($alertWarehouse, $warehouseIds, true)) {
            return false;
        }

        if ($role === 'DRIVER') {
            if (! empty($alert['userId'])) {
                return $alert['userId'] === $userId;
            }

            return in_array('DRIVER', $alert['roles'] ?? [], true);
        }

        if (in_array($role, self::OVERSIGHT, true)) {
            return true;
        }
        if (! empty($alert['userId']) && $alert['userId'] === $userId) {
            return true;
        }
        $roles = $alert['roles'] ?? [];
        if ($roles) {
            return in_array($role, $roles, true);
        }

        return true;
    }

    public static function href(array $alert, string $role): string
    {
        if ($role === 'DRIVER') {
            return '/driver/today';
        }
        $related = (string) ($alert['relatedId'] ?? '');
        $domain = (string) ($alert['domain'] ?? '');

        if ($domain === 'stock') {
            if (str_starts_with($related, 'rcp') || str_starts_with($related, 'rcpt')) {
                return '/procurement?tab=receipts&receipt='.$related;
            }
            $action = (string) ($alert['action'] ?? '');
            $warehouse = (string) ($alert['warehouseId'] ?? '');
            if ($action === 'replenish' && $related !== '' && ! str_starts_with($related, 'stk')) {
                $query = 'product='.$related.($warehouse !== '' ? '&warehouse='.$warehouse : '').'&action=da';

                return '/analytics/forecasts?'.$query;
            }
            if ($related !== '' && ! str_starts_with($related, 'stk')) {
                $query = 'article='.$related.($warehouse !== '' ? '&warehouse='.$warehouse : '');

                return '/stock?'.$query;
            }

            return '/stock';
        }
        if ($domain === 'procurement') {
            if (str_starts_with($related, 'po') || str_starts_with($related, 'ord')) {
                $action = (string) ($alert['action'] ?? '');
                $suffix = $action === 'receive' ? '&action=receive' : '';

                return '/procurement?tab=orders&order='.$related.$suffix;
            }
            if (str_starts_with($related, 'pr') || str_starts_with($related, 'da')) {
                return '/procurement?request='.$related;
            }

            return '/procurement';
        }
        if ($domain === 'logistics') {
            if (str_starts_with($related, 'bl') || str_starts_with($related, 'sn')) {
                return '/logistics?tab=notes&note='.$related;
            }
            if (str_starts_with($related, 'dlv') || str_starts_with($related, 'del')) {
                return '/logistics?tab=deliveries&delivery='.$related;
            }
            if (str_starts_with($related, 'rt')) {
                return '/logistics?tab=routes&route='.$related;
            }

            return '/logistics';
        }
        if ($domain === 'finance') {
            return '/finance';
        }

        return '/tasks';
    }

    /** Libellé CTA Today / file ops selon l’action métier de l’alerte. */
    public static function actionLabel(array $alert): string
    {
        return match ((string) ($alert['action'] ?? '')) {
            'replenish' => 'Créer la DA',
            'receive' => 'Ouvrir le BR',
            'stock' => 'Voir le stock',
            'dispatch' => 'Voir la livraison',
            default => 'Traiter',
        };
    }
}
