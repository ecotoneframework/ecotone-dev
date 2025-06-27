<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\BusinessInterface;

/**
 * licence Apache-2.0
 */
final readonly class CreateTicketCommand
{
    public function __construct(
        public string $title,
        public string $description,
        public string $priority = 'normal'
    ) {
    }
}
