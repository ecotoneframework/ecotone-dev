<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage;

use Ecotone\Messaging\Attribute\Converter;

final class EventConverter
{
    #[Converter]
    public function convertFromEvent(ActionCalled $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public function convertToEvent(array $payload): ActionCalled
    {
        return new ActionCalled($payload['id']);
    }
}
