<?php

namespace App\Exceptions;

class BybitRateLimitException extends BybitApiException
{
    public function __construct(?int $httpStatus = null, ?int $retCode = null)
    {
        parent::__construct('Bybit rate limit exceeded after retrying.', $httpStatus, $retCode);
    }
}
