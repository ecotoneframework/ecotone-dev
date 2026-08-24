<?php

declare(strict_types=1);

namespace App\Testing\Infrastructure;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\Dbal\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ServiceContext;
use Ecotone\Messaging\Channel\MessageChannelBuilder;

final class MessagingConfiguration
{
    const ASYNCHRONOUS_MESSAGES = "asynchronous_messages_channel";

    #[ServiceContext]
    public function registerInMemoryPollableChannel() : MessageChannelBuilder
    {
        return DbalBackedMessageChannelBuilder::create(self::ASYNCHRONOUS_MESSAGES);
    }

    #[ServiceContext]
    public function registerDocumentStoreRepository(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()->withDocumentStore(enableDocumentStoreStateStoredRepository: true);
    }
}