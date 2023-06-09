<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\User;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ramsey\Uuid\UuidInterface;

#[Aggregate]
final class User
{
    public function __construct(#[AggregateIdentifier] private UuidInterface $userId, private string $fullName)
    {

    }

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }
}