<?php

namespace App\Support;

class DocumentCodes
{
    public static function request(string $id, ?string $code = null): string
    {
        if ($code) {
            return $code;
        }
        $digits = preg_replace('/\D/', '', $id) ?: '0';

        return 'DA-'.now()->format('Y').'-'.str_pad(substr($digits, -5), 5, '0', STR_PAD_LEFT);
    }

    public static function order(string $id, ?string $code = null): string
    {
        if ($code) {
            return $code;
        }
        $digits = preg_replace('/\D/', '', $id) ?: '0';

        return 'OA-'.now()->format('Y').'-'.str_pad(substr($digits, -5), 5, '0', STR_PAD_LEFT);
    }

    public static function receipt(string $id, ?string $code = null): string
    {
        if ($code) {
            return $code;
        }
        $digits = preg_replace('/\D/', '', $id) ?: '0';

        return 'BR-'.now()->format('Y').'-'.str_pad(substr($digits, -5), 5, '0', STR_PAD_LEFT);
    }
}
