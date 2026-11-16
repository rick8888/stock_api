<?php

namespace App\Support;

use App\Exceptions\DomainException;

/**
 * Tarifs d'un plan par pays.
 * Format du document plan : prices = { "MA": { monthly, yearly, stripePriceMonthly?, stripePriceYearly? }, ... }
 * Un pays absent de prices n'est pas proposé avec ce plan.
 */
final class PlanPricing
{
    /**
     * Tarif du plan pour un pays, ou null s'il n'y est pas proposé.
     *
     * @param  array<string, mixed>  $plan
     * @return array{monthly: int, yearly: int, currency: string, stripePriceMonthly: string, stripePriceYearly: string}|null
     */
    public static function for(array $plan, string $country): ?array
    {
        $country = strtoupper($country);
        $row = $plan['prices'][$country] ?? null;

        // Plans antérieurs aux tarifs par pays : les prix historiques sont en MAD.
        if (! is_array($row) && $country === BillingCountries::DEFAULT && empty($plan['prices'])) {
            $row = [
                'monthly' => $plan['monthlyPrice'] ?? 0,
                'yearly' => $plan['yearlyPrice'] ?? 0,
                'stripePriceMonthly' => $plan['stripePriceMonthly'] ?? '',
                'stripePriceYearly' => $plan['stripePriceYearly'] ?? '',
            ];
        }
        if (! is_array($row)) {
            return null;
        }

        return [
            'monthly' => (int) ($row['monthly'] ?? 0),
            'yearly' => (int) ($row['yearly'] ?? 0),
            'currency' => BillingCountries::currency($country),
            'stripePriceMonthly' => trim((string) ($row['stripePriceMonthly'] ?? '')),
            'stripePriceYearly' => trim((string) ($row['stripePriceYearly'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{monthly: int, yearly: int, currency: string, stripePriceMonthly: string, stripePriceYearly: string}
     */
    public static function require(array $plan, string $country): array
    {
        $price = self::for($plan, $country);
        if (! $price) {
            $planName = $plan['name'] ?? 'Ce plan';
            throw new DomainException("{$planName} n’a pas de tarif pour ".BillingCountries::name($country).'.');
        }

        return $price;
    }

    public static function amount(array $plan, string $country, string $interval): int
    {
        $price = self::require($plan, $country);

        return $interval === 'yearly' ? $price['yearly'] : $price['monthly'];
    }

    /**
     * Nettoie la saisie : seuls les pays connus, montants entiers >= 0.
     * Un pays sans prix mensuel est retiré (plan non proposé dans ce pays).
     *
     * @return array<string, array{monthly: int, yearly: int, stripePriceMonthly?: string, stripePriceYearly?: string}>
     */
    public static function sanitize(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $prices = [];
        foreach (BillingCountries::codes() as $code) {
            $row = $input[$code] ?? null;
            if (! is_array($row) || ! isset($row['monthly']) || $row['monthly'] === '' || $row['monthly'] === null) {
                continue;
            }
            $monthly = max(0, (int) round((float) $row['monthly']));
            $yearly = isset($row['yearly']) && $row['yearly'] !== '' && $row['yearly'] !== null
                ? max(0, (int) round((float) $row['yearly']))
                : $monthly * 10;
            $clean = ['monthly' => $monthly, 'yearly' => $yearly];
            foreach (['stripePriceMonthly', 'stripePriceYearly'] as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $clean[$key] = $value;
                }
            }
            $prices[$code] = $clean;
        }

        return $prices;
    }
}
