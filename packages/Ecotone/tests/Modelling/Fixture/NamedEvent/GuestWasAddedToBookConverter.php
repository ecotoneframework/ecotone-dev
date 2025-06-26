<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\NamedEvent;

use Ecotone\Messaging\Attribute\Converter;

class GuestWasAddedToBookConverter
{
    #[Converter]
    public function toObject(array $data): GuestWasAddedToBook
    {
        return new GuestWasAddedToBook($data['bookId'], $data['guestName']);
    }

    #[Converter]
    public function toArray(GuestWasAddedToBook $guestWasAddedToBook): array
    {
        return [
            'bookId' => $guestWasAddedToBook->getBookId(),
            'guestName' => $guestWasAddedToBook->getName(),
        ];
    }
}