<?php

namespace App\Exceptions;

use Exception;

class SessionRequiredException extends Exception
{
    public function __construct()
    {
        parent::__construct('工作階段無效，請重新整理後再試');
    }
}
