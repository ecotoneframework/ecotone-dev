<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixture\Ticketing;

use Ecotone\Modelling\Attribute\Repository;
use Ecotone\Modelling\StandardRepository;

/**
 * licence Apache-2.0
 */
#[Repository]
final class InMemoryTicketRepository implements StandardRepository
{
    private array $tickets = [];

    public function canHandle(string $aggregateClassName): bool
    {
        return $aggregateClassName === Ticket::class;
    }

    public function findBy(string $aggregateClassName, array $identifiers): ?object
    {
        return $this->tickets[array_pop($identifiers)] ?? null;
    }

    public function save(array $identifiers, object $aggregate, array $metadata, ?int $versionBeforeHandling): void
    {
        $this->tickets[array_pop($identifiers)] = $aggregate;
    }
}
