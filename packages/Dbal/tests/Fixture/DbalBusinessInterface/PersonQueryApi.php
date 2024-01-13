<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Dbal\Attribute\DbalQueryBusinessMethod;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;
use Ecotone\Messaging\Conversion\MediaType;

interface PersonQueryApi
{
    /**
     * @return array<int, array{person_id: string}>
     */
    #[DbalQueryBusinessMethod('SELECT person_id FROM persons ORDER BY person_id ASC LIMIT :limit OFFSET :offset')]
    public function getPersonIds(int $limit, int $offset): array;

    /**
     * @return int[]
     */
    #[DbalQueryBusinessMethod(
        'SELECT person_id FROM persons ORDER BY person_id ASC LIMIT :limit OFFSET :offset',
        fetchMode: FetchMode::FIRST_COLUMN
    )]
    public function getExtractedPersonIds(int $limit, int $offset): array;

    #[DbalQueryBusinessMethod(
        'SELECT COUNT(*) FROM persons',
        fetchMode: FetchMode::FIRST_COLUMN_OF_FIRST_ROW
    )]
    public function countPersons(): int;

    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons LIMIT :limit OFFSET :offset')]
    public function getNameList(int $limit, int $offset): array;

    #[DbalQueryBusinessMethod(
        'SELECT person_id, name FROM persons WHERE person_id = :personId',
        fetchMode: FetchMode::FIRST_ROW
    )]
    public function getNameDTO(int $personId): PersonNameDTO;

    #[DbalQueryBusinessMethod(
        'SELECT person_id, name FROM persons WHERE person_id = :personId',
        fetchMode: FetchMode::FIRST_ROW,
        replyContentType: MediaType::APPLICATION_JSON
    )]
    public function getNameDTOInJson(int $personId): string;

    #[DbalQueryBusinessMethod(
        'SELECT person_id, name FROM persons WHERE person_id = :personId',
        fetchMode: FetchMode::FIRST_ROW
    )]
    public function getNameDTOOrNull(int $personId): PersonNameDTO|null;

    #[DbalQueryBusinessMethod(
        'SELECT person_id, name FROM persons WHERE person_id = :personId',
        fetchMode: FetchMode::FIRST_ROW
    )]
    public function getNameDTOOrFalse(int $personId): PersonNameDTO|false;

    /**
     * @return PersonNameDTO[]
     */
    #[DbalQueryBusinessMethod('SELECT person_id, name FROM persons LIMIT :limit OFFSET :offset')]
    public function getNameListDTO(int $limit, int $offset): array;

    /**
     * @return iterable<PersonNameDTO>
     */
    #[DbalQueryBusinessMethod(
        'SELECT person_id, name FROM persons ORDER BY person_id ASC',
        fetchMode: FetchMode::ITERATE
    )]
    public function getPersonIdsIterator(): iterable;
}
