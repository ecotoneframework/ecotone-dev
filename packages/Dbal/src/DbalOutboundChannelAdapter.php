<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\DbalDestination;

/**
 * licence Apache-2.0
 */
class DbalOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function __construct(CachedConnectionFactory $connectionFactory, private string $queueName, bool $autoDeclare, OutboundMessageConverter $outboundMessageConverter, ConversionService $conversionService)
    {
        parent::__construct(
            $connectionFactory,
            new DbalDestination($this->queueName),
            $autoDeclare,
            $outboundMessageConverter,
            $conversionService
        );
    }

    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        $context->createDataBaseTable();
        $context->createQueue($this->queueName);
    }
}
