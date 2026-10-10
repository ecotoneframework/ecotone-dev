<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;

/**
 * licence Enterprise
 */
class ErrorConfigurationContext
{
    public const ERROR_CHANNEL = DbalDeadLetterBuilder::STORE_CHANNEL;
    public const ASYNC_REPLY_CHANNEL = 'asyncReplyChannel';

    #[ServiceContext]
    public function dbalConfiguration()
    {
        return DbalConfiguration::createWithDefaults()
            ->withDeadLetter(true, 'managerRegistry')
            ->withDefaultConnectionReferenceNames(['managerRegistry']);
    }

    #[ServiceContext]
    public function asyncReplyChannel()
    {
        return DbalBackedMessageChannelBuilder::create(self::ASYNC_REPLY_CHANNEL, 'managerRegistry')
            ->withReceiveTimeout(1000);
    }
}
