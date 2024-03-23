<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator\MessageHandler;

use Ecotone\Messaging\Attribute\MessageHandler;

final readonly class ExampleMessageHandlerChangingHeaders
{
    #[MessageHandler('someRequestChannel', endpointId: 'test', changingHeaders: true)]
    public function test(): void
    {
    }
}