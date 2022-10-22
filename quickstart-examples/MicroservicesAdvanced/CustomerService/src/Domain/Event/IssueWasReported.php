<?php

namespace App\Microservices\CustomerService\Domain\Event;

use Ramsey\Uuid\UuidInterface;

class IssueWasReported
{
    public function __construct(public UuidInterface $issueId) {}
}
