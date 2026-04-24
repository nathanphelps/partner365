<?php

namespace App\Services\Exceptions;

use Exception;

class BridgeException extends Exception
{
    public function __construct(
        string $message = '',
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
