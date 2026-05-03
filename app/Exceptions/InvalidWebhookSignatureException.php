<?php

namespace App\Exceptions;

use Exception;

final class InvalidWebhookSignatureException extends Exception
{
    public function __construct()
    {
        parent::__construct('Webhook signature verification failed.');
    }
}
