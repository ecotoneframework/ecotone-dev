<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
class ErrorConfigurationContext
{
    public const ERROR_CHANNEL = DbalDeadLetterBuilder::STORE_CHANNEL;

    #[ServiceContext]
    public function dbalConfiguration()
    {
        return DbalConfiguration::createWithDefaults()
            ->withDeadLetter(true, 'managerRegistry')
            ->withDefaultConnectionReferenceNames(['managerRegistry']);
    }
}
