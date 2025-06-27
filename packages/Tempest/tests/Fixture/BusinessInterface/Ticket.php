<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\BusinessInterface;

/**
 * licence Apache-2.0
 */
final class Ticket
{
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $priority,
        public bool $isClosed = false
    ) {
    }

    public static function create(string $id, string $title, string $description, string $priority): self
    {
        return new self($id, $title, $description, $priority);
    }

    public function close(): void
    {
        $this->isClosed = true;
    }
}
