<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\User;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;

#[Aggregate]
final class User
{
    public function __construct(#[Identifier] private UuidInterface $userId, private string $fullName)
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