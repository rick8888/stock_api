<?php

namespace App\Exceptions;

use Exception;

class DomainException extends Exception
{
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
