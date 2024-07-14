<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use DateTimeImmutable;
use Ecotone\Dbal\Attribute\DbalQuery;
use Ecotone\Dbal\Attribute\DbalWrite;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;

/**
 * licence Apache-2.0
 */
interface ActivityService
{
    #[DbalWrite('INSERT INTO activities VALUES (:personId, :activity, :time)')]
    public function add(string $personId, string $activity, DateTimeImmutable $time): void;

    /**
     * @return string[]
     */
    #[DbalQuery(
        'SELECT person_id FROM activities WHERE type = :activity AND occurred_at >= :atOrAfter',
        fetchMode: FetchMode::FIRST_COLUMN
    )]
    public function findAfterOrAt(string $activity, DateTimeImmutable $atOrAfter): array;

    /**
     * @return string[]
     */
    #[DbalQuery(
        'SELECT person_id FROM activities WHERE type = :activity AND occurred_at < :atOrAfter',
        fetchMode: FetchMode::FIRST_COLUMN
    )]
    public function findBefore(string $activity, DateTimeImmutable $atOrAfter): array;

    #[DbalWrite('INSERT INTO activities VALUES (:personId, :activity, :time)')]
    public function store(PersonId $personId, string $activity, DateTimeImmutable $time): void;
}
