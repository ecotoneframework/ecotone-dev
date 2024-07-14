<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

/**
 * licence Apache-2.0
 */
final class PersonName
{
    public function __construct(
        private string $name
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toLowerCase(): string
    {
        return strtolower($this->name);
    }
}
