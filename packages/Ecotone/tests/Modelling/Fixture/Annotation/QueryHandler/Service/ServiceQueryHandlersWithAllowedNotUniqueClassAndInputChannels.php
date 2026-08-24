<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\NotUniqueHandler;
use stdClass;

/**
 * licence Apache-2.0
 */
class ServiceQueryHandlersWithAllowedNotUniqueClassAndInputChannels
{
    #[CommandHandler('some1')]
    #[NotUniqueHandler]
    public function execute1(stdClass $class): int
    {
    }

    #[CommandHandler('some2')]
    #[NotUniqueHandler]
    public function execute2(stdClass $class): int
    {
    }
}
