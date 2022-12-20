<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\Destination;
use Interop\Queue\Message as EnqueueMessage;

abstract class EnqueueInboundChannelAdapter implements TaskExecutor
{
    protected abstract function getEntrypointGateway(): InboundChannelAdapterEntrypoint;

    protected abstract function getCachedConnectionFactory(): CachedConnectionFactory;

    protected abstract function getDestination() : Destination;

    protected abstract function isDeclaredOnStartup(): bool;

    protected abstract function getReceiveTimeoutInMilliseconds(): int;

    protected abstract function getInboundMessageConverter(): InboundMessageConverter;

    private bool $initialized = false;

    public function execute(PollingMetadata $pollingMetadata): void
    {
        $message = $this->receiveMessage($pollingMetadata->getExecutionTimeLimitInMilliseconds());

        if ($message) {
            $this->getEntrypointGateway()->executeEntrypoint($message);
        }
    }

    public function receiveMessage(int $timeout = 0): ?Message
    {
        if ($this->isDeclaredOnStartup() && is_null($this->initialized)) {
            /** @var DbalContext $context */
            $context = $this->getCachedConnectionFactory()->createContext();

            $context->createDataBaseTable();
            $context->createQueue($this->destination);

            $this->initialized = true;
        }

        $consumer = $this->getCachedConnectionFactory()->getConsumer($this->getDestination());

        try {
            /** @var EnqueueMessage $message */
            $message = $consumer->receive($timeout ?: $this->getReceiveTimeoutInMilliseconds());
        }catch (\Exception $exception) {
            throw new ConnectionException('There was a problem while polling amqp channel', 0, $exception);
        }

        if (! $message) {
            return null;
        }

        return $this->getInboundMessageConverter()->toMessage($message, $consumer)->build();
    }
}