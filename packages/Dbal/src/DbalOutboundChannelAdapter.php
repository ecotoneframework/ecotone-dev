<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Enqueue\OutboundMessageConverter;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\DbalDestination;
use Enqueue\Dbal\DbalMessage;
use Exception;

class DbalOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        $context->createDataBaseTable();
        $context->createQueue($this->queueName);
    }
}
