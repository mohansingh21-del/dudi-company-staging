<?php

namespace App\Exceptions;

use Exception;

class VecvApiException extends Exception
{
    /**
     * HTTP status returned by the VECV gateway, when there was one.
     *
     * @var int|null
     */
    protected $statusCode;

    public function __construct($message = '', $statusCode = null, $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->statusCode = $statusCode;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * VECV signals rate limiting with 409, not 429. Both are treated as such.
     *
     * @return bool
     */
    public function isRateLimited()
    {
        return in_array($this->statusCode, [409, 429], true);
    }
}
