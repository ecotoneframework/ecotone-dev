<?php

namespace App\Microservices\CustomerService\Domain\Command;

use App\Microservices\CustomerService\Domain\Email;
use Ramsey\Uuid\UuidInterface;

class ReportIssue
{
    public UuidInterface $issueId;
    public Email $email;
    public string $content;
}
