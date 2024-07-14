<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterfaceCommandHandler;

/**
 * licence Apache-2.0
 */
final class RegisterPerson
{
    public function __construct(public int $personId, public string $name)
    {
    }
}
