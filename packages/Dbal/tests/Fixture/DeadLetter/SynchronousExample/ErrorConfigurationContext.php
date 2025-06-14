<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

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
