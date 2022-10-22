<?php

namespace App\Microservices\CustomerService\Domain\Event;

use Ramsey\Uuid\UuidInterface;

final class IssueWasClosed
{
    public function __construct(public UuidInterface $issueId) {}
}
