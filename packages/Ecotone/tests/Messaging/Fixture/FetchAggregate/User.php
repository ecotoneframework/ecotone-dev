<?php

namespace Test\Ecotone\Messaging\Fixture\FetchAggregate;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Identifier;

#[Aggregate]
/**
 * licence Enterprise
 */
class User
{
    public function __construct(
        #[Identifier] private string $userId,
        private string $name
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function changeName(string $newName): void
    {
        $this->name = $newName;
    }
}
