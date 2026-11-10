<?php

namespace App\Support;

/**
 * Rebase les dates du jeu de démo (20–31 août 2026) vers la semaine courante.
 */
class DemoDate
{
    private const DEMO_FROM = '2026-08-20';

    private const DEMO_TO = '2026-08-31';

    private const DEMO_TODAY = '2026-08-26';

    public static function rebase(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return $iso;
        }
        $key = substr($iso, 0, 10);
        if ($key < self::DEMO_FROM || $key > self::DEMO_TO) {
            return $iso;
        }

        try {
            $event = new \DateTimeImmutable($iso);
            $demo = new \DateTimeImmutable(self::DEMO_TODAY);
            $today = new \DateTimeImmutable('today');
            $days = (int) $demo->diff($today)->format('%r%a');
            $shifted = $event->modify($days.' days');
        } catch (\Exception) {
            return $iso;
        }

        return strlen($iso) > 10
            ? $shifted->format('Y-m-d\TH:i:s.000\Z')
            : $shifted->format('Y-m-d');
    }
}
