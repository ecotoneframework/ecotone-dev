<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProjectionHandlersExecutionRoutingTest;

use Ecotone\Messaging\Attribute\Converter;

class Converters
{
    #[Converter]
    public function convertToArray(AnEvent $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public function convertToObject(array $data): AnEvent
    {
        return new AnEvent($data['id']);
    }
}