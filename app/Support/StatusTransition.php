<?php

namespace App\Support;

use App\Exceptions\DomainException;

/**
 * Valide les transitions de statut métier (machines d'état).
 */
final class StatusTransition
{
    /** @var array<string, array<string, list<string>>> */
    private const MAP = [
        'purchase_order' => [
            'draft' => ['sent', 'cancelled'],
            'sent' => ['confirmed', 'delayed', 'cancelled'],
            'confirmed' => ['partial', 'received', 'delayed', 'cancelled'],
            'delayed' => ['confirmed', 'partial', 'received', 'cancelled'],
            'partial' => ['received', 'cancelled'],
            'received' => [],
            'cancelled' => [],
        ],
        'receipt' => [
            'pending' => ['checking', 'partial', 'validated'],
            'checking' => ['partial', 'validated', 'pending'],
            'partial' => ['validated'],
            'validated' => [],
        ],
        'shipment' => [
            'preparing' => ['in_transit', 'delayed', 'done'],
            'in_transit' => ['done', 'delayed'],
            'delayed' => ['in_transit', 'done'],
            'done' => [],
        ],
        'route' => [
            'planned' => ['in_progress', 'done'],
            'in_progress' => ['done'],
            'done' => [],
        ],
        'shipping_note' => [
            'ready' => ['picking', 'loaded', 'assigned', 'delivered'],
            'picking' => ['ready', 'loaded', 'assigned'],
            'loaded' => ['assigned', 'delivered', 'ready'],
            'assigned' => ['delivered', 'ready', 'loaded'],
            'delivered' => [],
        ],
        'delivery' => [
            'prepared' => ['in_transit', 'at_risk', 'delivered', 'failed'],
            'in_transit' => ['at_risk', 'delivered', 'failed'],
            'at_risk' => ['in_transit', 'delivered', 'failed'],
            'delivered' => [],
            'failed' => [],
        ],
    ];

    public static function assert(string $entity, string $from, string $to, string $label = 'Statut'): void
    {
        if ($from === $to) {
            return;
        }
        $allowed = self::MAP[$entity][$from] ?? null;
        if ($allowed === null) {
            throw new DomainException($label.' de départ inconnu : '.$from);
        }
        if (! in_array($to, $allowed, true)) {
            throw new DomainException(
                sprintf('%s : transition %s → %s interdite.', $label, $from, $to),
            );
        }
    }

    /** @return list<string> */
    public static function allowed(string $entity, string $from): array
    {
        return self::MAP[$entity][$from] ?? [];
    }
}
