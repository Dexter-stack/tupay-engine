<?php

namespace App\Exceptions;

use Exception;

final class SwapInProgressException extends Exception
{
    public function __construct()
    {
        parent::__construct('A swap is already in progress. Please wait and try again.');
    }
}
