<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueAcknowledgementCallback;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;

final class AmqpAcknowledgeCallbackWraper implements AcknowledgementCallback
{
    public function __construct(private EnqueueAcknowledgementCallback $acknowledgementCallback, private CachedConnectionFactory $connectionFactory, private LoggingGateway $loggingGateway)
    {

    }

    public function isAutoAck(): bool
    {
        return $this->acknowledgementCallback->isAutoAck();
    }

    public function disableAutoAck(): void
    {
        $this->acknowledgementCallback->disableAutoAck();
    }

    public function accept(): void
    {
        try {
            $this->acknowledgementCallback->accept();
        }catch (\Exception $exception) {
            $this->loggingGateway->info("Failed to acknowledge message, disconnecting AMQP Connection in order to self-heal. Failure happen due to: " . $exception->getMessage(), exception: $exception);

            $this->connectionFactory->reconnect();
        }
    }

    public function reject(): void
    {
        try {
            $this->acknowledgementCallback->reject();
        }catch (\Exception $exception) {
            $this->loggingGateway->info("Failed to reject message, disconnecting AMQP Connection in order to self-heal. Failure happen due to: " . $exception->getMessage(), exception: $exception);

            $this->connectionFactory->reconnect();
        }
    }

    public function requeue(): void
    {
        try {
            $this->acknowledgementCallback->requeue();
        }catch (\Exception $exception) {
            $this->loggingGateway->info("Failed to requeue message, disconnecting AMQP Connection in order to self-heal. Failure happen due to: " . $exception->getMessage(), exception: $exception);

            $this->connectionFactory->reconnect();
        }
    }
}