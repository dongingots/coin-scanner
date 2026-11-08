<?php

namespace App\Exceptions;

use RuntimeException;

class BybitApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retCode = null,
    ) {
        parent::__construct($message, $retCode ?? 0);
    }
}
