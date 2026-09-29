<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Throwable;

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

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        try {
            return parent::receiveWithTimeout($pollingMetadata);
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager, $this->connectionFactory);
        }
    }

    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();
        $connection = $context->getDbalConnection();

        if ($this->tableManager->isInitialized($connection)) {
            return;
        }

        if (! $this->tableManager->shouldBeInitializedAutomatically($connection)) {
            throw ConfigurationException::create($this->tableManager->getMissingTableInstructions($connection));
        }

        $this->tableManager->createTable($connection);
    }

    public function connectionException(): array
    {
        return [ConnectionException::class];
    }
}
