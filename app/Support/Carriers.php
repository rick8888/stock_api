<?php

namespace App\Support;

class Carriers
{
    /**
     * @return list<array{id:string,name:string,code:string,kind:string,trackingUrlTemplate?:string,active:bool}>
     */
    public static function catalog(): array
    {
        return [
            [
                'id' => 'car-fleet',
                'name' => 'Flotte interne',
                'code' => 'FLEET',
                'kind' => 'own_fleet',
                'active' => true,
            ],
            [
                'id' => 'car-ctm',
                'name' => 'CTM Messagerie',
                'code' => 'CTM',
                'kind' => 'freight',
                'trackingUrlTemplate' => 'https://www.ctm.ma/suivi?ref={tracking}',
                'active' => true,
            ],
            [
                'id' => 'car-amana',
                'name' => 'Amana (Barid)',
                'code' => 'AMANA',
                'kind' => 'parcel',
                'trackingUrlTemplate' => 'https://www.amap.ma/suivi/{tracking}',
                'active' => true,
            ],
            [
                'id' => 'car-chrono',
                'name' => 'Chronopost Maroc',
                'code' => 'CHRONO',
                'kind' => 'parcel',
                'trackingUrlTemplate' => 'https://www.chronopost.ma/tracking/{tracking}',
                'active' => true,
            ],
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::catalog() as $carrier) {
            if ($carrier['id'] === $id && ($carrier['active'] ?? true)) {
                return $carrier;
            }
        }

        return null;
    }

    public static function bookTracking(array $carrier, string $noteCode): string
    {
        $stamp = strtoupper(substr(base_convert((string) time(), 10, 36), -6));
        $suffix = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $noteCode) ?: 'BL', -5));
        if (($carrier['kind'] ?? '') === 'own_fleet') {
            return 'FLEET-'.$suffix.'-'.$stamp;
        }

        return ($carrier['code'] ?? 'CAR').'-'.$suffix.'-'.$stamp;
    }
}
