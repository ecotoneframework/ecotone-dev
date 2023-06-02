<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga;

use Ecotone\Messaging\Attribute\Converter;

class SagaEventConverter
{
    #[Converter]
    public function fromSagaStarted(SagaStarted $event): array
    {
        return [
            'id' => $event->getId(),
        ];
    }

    #[Converter]
    public function toTicketWasRegistered(array $event): SagaStarted
    {
        return new SagaStarted($event['id']);
    }
}
