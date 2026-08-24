<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\IgnorePayload;
use stdClass;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class ServiceCommandHandlerWithInputChannelNameAndIgnoreMessage
{
    #[CommandHandler('execute', 'commandHandler')]
    #[IgnorePayload]
    public function execute(stdClass $class): int
    {
        return 1;
    }
}
