<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore\Dbal;

use Exception;
use RuntimeException;

class DriverException extends RuntimeException
{
    public function __construct(int $code, Exception $previous)
    {
        parent::__construct($previous->getMessage(), $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->getCode();
    }
}