<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Enqueue\Dbal\DbalContext;

/**
 * licence Apache-2.0
 */
class DbalInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function __construct(
        CachedConnectionFactory $connectionFactory,
        bool $declareOnStartup,
        string $queueName,
        int $receiveTimeoutInMilliseconds,
        InboundMessageConverter $inboundMessageConverter,
        ConversionService $conversionService,
        private EnqueueTableManager $tableManager,
    ) {
        parent::__construct($connectionFactory, $declareOnStartup, $queueName, $receiveTimeoutInMilliseconds, $inboundMessageConverter, $conversionService);
    }

    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        if (! $this->tableManager->shouldBeInitializedAutomatically()) {
            return;
        }

        $this->tableManager->createTable($context->getDbalConnection());
    }

    public function connectionException(): array
    {
        return [ConnectionException::class];
    }
}
