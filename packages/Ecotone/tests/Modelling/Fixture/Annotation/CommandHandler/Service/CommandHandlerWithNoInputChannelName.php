<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service;

use Ecotone\Api\CommandHandler;

/**
 * licence Apache-2.0
 */
class CommandHandlerWithNoInputChannelName
{
    #[CommandHandler]
    public function noAction(): void
    {
    }
}
