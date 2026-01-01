<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\EventNameFiltering;

use Ecotone\Messaging\Attribute\Converter;

class Converters
{
    #[Converter]
    public function convertFirstEventToArray(FirstEvent $event): array
    {
        return ['id' => $event->id, 'type' => 'first'];
    }

    #[Converter]
    public function convertArrayToFirstEvent(array $data): FirstEvent
    {
        return new FirstEvent($data['id']);
    }

    #[Converter]
    public function convertSecondEventToArray(SecondEvent $event): array
    {
        return ['id' => $event->id, 'type' => 'second'];
    }

    #[Converter]
    public function convertArrayToSecondEvent(array $data): SecondEvent
    {
        return new SecondEvent($data['id']);
    }
}
