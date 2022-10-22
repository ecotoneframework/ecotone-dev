<?php

namespace App\Microservices\CustomerService\Domain;

use Ecotone\Modelling\Attribute\Repository;
use Ramsey\Uuid\UuidInterface;

interface IssueRepository
{
    #[Repository]
    public function get(UuidInterface $issueId): Issue;
}