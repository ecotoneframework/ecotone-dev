<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service;

use Ecotone\Modelling\Attribute\CommandHandler;
use stdClass;

/**
 * licence Apache-2.0
 */
class CommandHandlerWithReturnValue
{
    #[CommandHandler('input', 'command-id')]
    public function execute(SomeCommand $command, stdClass $service1): int
    {
        return 1;
    }
}
