<?php

namespace App\Exceptions;

use Exception;

class TooManyLoginAttemptsException extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('登入嘗試次數過多，請稍後再試');
    }
}
