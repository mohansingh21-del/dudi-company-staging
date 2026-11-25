<?php

namespace App\Exceptions;

use Exception;

class TruckConnectApiException extends Exception
{
    /**
     * HTTP or envelope status returned by Truck Connect, when there was one.
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
     * The vendor documents no rate limit on this endpoint - it is meant to be
     * polled every minute - but the standard codes are honoured if one appears.
     *
     * @return bool
     */
    public function isRateLimited()
    {
        return in_array($this->statusCode, [409, 429], true);
    }
}
