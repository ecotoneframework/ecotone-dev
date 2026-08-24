<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator\MessageHandler;

use Ecotone\Api\InternalHandler;

final readonly class ExampleMessageHandlerChangingHeaders
{
    #[InternalHandler('someRequestChannel', endpointId: 'test', changingHeaders: true)]
    public function test(): void
    {
    }
}
