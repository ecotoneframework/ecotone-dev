<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\MessageHandlerFlow;

use Ecotone\Messaging\Attribute\MessageHandler;

final readonly class ExampleMessageHandler
{
    #[MessageHandler('handleMessage')]
    public function handle(string $payoad): void
    {

    }
}