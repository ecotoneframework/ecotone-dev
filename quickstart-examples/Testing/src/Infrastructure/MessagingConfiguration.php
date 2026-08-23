<?php

declare(strict_types=1);

namespace App\Testing\Infrastructure;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Dbal\Api\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
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