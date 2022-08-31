<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\Example;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class ErrorConfigurationContext
{
    public const INPUT_CHANNEL = 'inputChannel';
    public const ERROR_CHANNEL = 'errorChannel';


    #[ServiceContext]
    public function getInputChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::INPUT_CHANNEL, 'managerRegistry')
            ->withReceiveTimeout(1);
    }

    #[ServiceContext]
    public function pollingConfiguration()
    {
        return PollingMetadata::create('orderService')
                ->setExecutionTimeLimitInMilliseconds(1)
                ->setHandledMessageLimit(1)
                ->setErrorChannelName(self::ERROR_CHANNEL);
    }

    #[ServiceContext]
    public function dbalConfiguration()
    {
        return DbalConfiguration::createWithDefaults()
            ->withDeadLetter(true, 'managerRegistry')
            ->withDefaultConnectionReferenceNames(['managerRegistry']);
    }
}
