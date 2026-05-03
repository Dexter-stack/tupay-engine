<?php

namespace App\Exceptions;

use Exception;

final class InsufficientFundsException extends Exception
{
    public function __construct(string $currency = '')
    {
        parent::__construct("Insufficient funds in {$currency} wallet.");
    }
}
