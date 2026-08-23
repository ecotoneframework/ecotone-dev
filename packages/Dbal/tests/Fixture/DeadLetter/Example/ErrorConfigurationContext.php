<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\Example;

use Ecotone\Dbal\Configuration\CustomDeadLetterGateway;
use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Dbal\Connection\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
class ErrorConfigurationContext
{
    public const INPUT_CHANNEL = 'inputChannel';
    public const CUSTOM_GATEWAY_REFERENCE_NAME = 'custom';


    #[ServiceContext]
    public function getInputChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::INPUT_CHANNEL, 'managerRegistry')
            ->withReceiveTimeout(1);
    }

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
