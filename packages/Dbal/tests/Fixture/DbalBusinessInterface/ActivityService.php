<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Dbal\Attribute\DbalQueryMethod;
use Ecotone\Dbal\Attribute\DbalWriteMethod;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;

interface ActivityService
{
    #[DbalWriteMethod('INSERT INTO activities VALUES (:personId, :activity, :time)')]
    public function add(string $personId, string $activity, \DateTimeImmutable $time): void;

    /**
     * @return string[]
     */
    #[DbalQueryMethod(
        'SELECT person_id FROM activities WHERE type = :activity AND occurred_at >= :atOrAfter',
        fetchMode: FetchMode::FIRST_COLUMN
    )]
    public function findAfterOrAt(string $activity, \DateTimeImmutable $atOrAfter): array;

    /**
     * @return string[]
     */
    #[DbalQueryMethod(
        'SELECT person_id FROM activities WHERE type = :activity AND occurred_at < :atOrAfter',
        fetchMode: FetchMode::FIRST_COLUMN
    )]
    public function findBefore(string $activity, \DateTimeImmutable $atOrAfter): array;

    #[DbalWriteMethod('INSERT INTO activities VALUES (:personId, :activity, :time)')]
    public function store(PersonId $personId, string $activity, \DateTimeImmutable $time): void;
}