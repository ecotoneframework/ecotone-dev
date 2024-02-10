<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterfaceCommandHandler;

final class RegisterPerson
{
    public function __construct(public int $personId, public string $name)
    {
    }
}
