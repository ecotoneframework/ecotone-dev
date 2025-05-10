<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Dbal\Configuration\CustomDeadLetterGateway;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
class ErrorConfigurationContext
{
    public const ERROR_CHANNEL = DbalDeadLetterBuilder::STORE_CHANNEL;
    public const CUSTOM_GATEWAY_REFERENCE_NAME = 'custom';

    #[ServiceContext]
    public function dbalConfiguration()
    {
        return DbalConfiguration::createWithDefaults()
            ->withDeadLetter(true, 'managerRegistry')
            ->withDefaultConnectionReferenceNames(['managerRegistry']);
    }

    #[ServiceContext]
    public function customDeadLetterGateway()
    {
        return CustomDeadLetterGateway::createWith(self::CUSTOM_GATEWAY_REFERENCE_NAME, DbalConnectionFactory::class);
    }
}
