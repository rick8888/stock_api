<?php

namespace App\Support;

class Uid
{
    public static function make(string $prefix): string
    {
        return $prefix.'-'.strtolower(bin2hex(random_bytes(4)));
    }
}
