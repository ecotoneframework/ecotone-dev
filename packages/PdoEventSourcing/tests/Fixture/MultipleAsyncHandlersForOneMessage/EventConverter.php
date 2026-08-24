<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage;

use Ecotone\Api\Converter;

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
