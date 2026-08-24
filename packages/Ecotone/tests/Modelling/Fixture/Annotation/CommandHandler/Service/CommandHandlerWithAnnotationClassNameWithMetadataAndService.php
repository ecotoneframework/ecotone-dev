<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\IgnorePayload;
use stdClass;

/**
 * licence Apache-2.0
 */
class CommandHandlerWithAnnotationClassNameWithMetadataAndService
{
    #[CommandHandler('input', 'command-id')]
    #[IgnorePayload]
    public function execute(array $metadata, stdClass $service): int
    {
        return 1;
    }
}
