<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

use Ecotone\Messaging\Attribute\Converter;

class PersonNameDTOConverter
{
    #[Converter]
    public function from(PersonNameDTO $personNameDTO): array
    {
        return [
            'person_id' => $personNameDTO->getPersonId(),
            'name' => $personNameDTO->getName(),
        ];
    }

    #[Converter]
    public function to(array $personNameDTO): PersonNameDTO
    {
        return new PersonNameDTO($personNameDTO['person_id'], $personNameDTO['name']);
    }
}
