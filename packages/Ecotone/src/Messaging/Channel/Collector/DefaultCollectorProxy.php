<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;

final class DefaultCollectorProxy
{
    /**
     * @param CollectedMessage[] $collectedMessages
     */
    public function proxy(array $collectedMessages, #[Reference] ConfiguredMessagingSystem $configuredMessagingSystem): void
    {
        foreach ($collectedMessages as $collectedMessage) {
            $configuredMessagingSystem->getMessageChannelByName($collectedMessage->getChannelName())->send($collectedMessage->getMessage());
        }
    }
}