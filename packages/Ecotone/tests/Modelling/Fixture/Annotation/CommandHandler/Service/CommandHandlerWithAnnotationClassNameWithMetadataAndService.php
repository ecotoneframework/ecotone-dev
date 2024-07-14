<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\IgnorePayload;
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
