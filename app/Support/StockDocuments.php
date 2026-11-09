<?php

namespace App\Support;

class StockDocuments
{
    public static function extractPieceCode(?string ...$values): string
    {
        foreach ($values as $value) {
            if ($value && preg_match('/\b((?:DA|OA|BR|BL|EX|TR|PV)-[A-Z0-9-]+)/i', $value, $match)) {
                return strtoupper($match[1]);
            }
        }

        return '';
    }

    public static function assertInbound(?string $reference, ?string $note): string
    {
        $code = self::extractPieceCode($reference, $note);
        if ($code === '' || ! preg_match('/^BR[-:]/i', $code)) {
            throw new \App\Exceptions\DomainException('Entrée de stock uniquement via un bon de réception (BR).');
        }

        return $code;
    }

    public static function assertOutbound(?string $reference, ?string $note): string
    {
        $code = self::extractPieceCode($reference, $note);
        if ($code === '' || ! preg_match('/^(BL|DA|EX|TR)[-:]/i', $code)) {
            throw new \App\Exceptions\DomainException('Sortie de stock uniquement via un BL, une tournée, une expédition ou une DA interne.');
        }

        return $code;
    }

    public static function assertInventoryAdjust(?string $reference, ?string $note): string
    {
        $code = self::extractPieceCode($reference, $note);
        if ($code !== '' && preg_match('/^PV-/i', $code)) {
            return $code;
        }
        if (str_starts_with((string) $reference, 'inv-') || str_contains(mb_strtolower((string) $note), 'inventaire')) {
            return $reference ?: $code ?: 'PV';
        }

        throw new \App\Exceptions\DomainException('Ajustement uniquement après validation d’un inventaire.');
    }
}
