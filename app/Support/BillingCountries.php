<?php

namespace App\Support;

use App\Exceptions\DomainException;

/**
 * Pays facturables et leur devise d'abonnement.
 * Doit rester aligné avec aria/src/config/billingCountries.ts.
 */
final class BillingCountries
{
    public const DEFAULT = 'MA';

    /** @var array<string, array{name: string, currency: string}> */
    public const ALL = [
        'MA' => ['name' => 'Maroc', 'currency' => 'MAD'],
        'CI' => ['name' => 'Côte d’Ivoire', 'currency' => 'XOF'],
        'SN' => ['name' => 'Sénégal', 'currency' => 'XOF'],
        'CD' => ['name' => 'RD Congo', 'currency' => 'USD'],
        'CG' => ['name' => 'Congo-Brazzaville', 'currency' => 'XAF'],
    ];

    /** Devises sans centimes côté Stripe (montant envoyé tel quel). */
    private const ZERO_DECIMAL = ['XOF', 'XAF'];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::ALL);
    }

    /** Code pays validé ; vide → pays par défaut, inconnu → erreur métier. */
    public static function normalize(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return self::DEFAULT;
        }
        if (! array_key_exists($code, self::ALL)) {
            throw new DomainException('Pays non pris en charge pour la facturation.');
        }

        return $code;
    }

    public static function name(string $code): string
    {
        return self::ALL[strtoupper($code)]['name'] ?? $code;
    }

    public static function currency(?string $code): string
    {
        return self::ALL[strtoupper((string) $code)]['currency'] ?? self::ALL[self::DEFAULT]['currency'];
    }

    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true);
    }

    /** Montant → unité mineure Stripe (centimes, ou unité pour XOF/XAF). */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        return self::isZeroDecimal($currency) ? (int) round($amount) : (int) round($amount * 100);
    }

    public static function fromMinorUnits(int $amount, string $currency): float
    {
        return self::isZeroDecimal($currency) ? (float) $amount : $amount / 100;
    }
}
