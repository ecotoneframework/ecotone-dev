<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Exception;
use Interop\Queue\Consumer as EnqueueConsumer;
use Interop\Queue\Message as EnqueueMessage;

/**
 * Class EnqueueAcknowledgementCallback
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class EnqueueAcknowledgementCallback implements AcknowledgementCallback
{
    public const AUTO_ACK = 'auto';
    public const MANUAL_ACK = 'manual';
    public const NONE = 'none';

    /**
     * @var bool
     */
    private $isAutoAck;
    /**
     * @var EnqueueConsumer
     */
    private $enqueueConsumer;
    /**
     * @var EnqueueMessage
     */
    private $enqueueMessage;

    /**
     * EnqueueAcknowledgementCallback constructor.
     * @param bool $isAutoAck
     * @param EnqueueConsumer $enqueueConsumer
     * @param EnqueueMessage $enqueueMessage
     */
    private function __construct(bool $isAutoAck, EnqueueConsumer $enqueueConsumer, EnqueueMessage $enqueueMessage, private CachedConnectionFactory $connectionFactory, private LoggingGateway $loggingGateway)
    {
        $this->isAutoAck = $isAutoAck;
        $this->enqueueConsumer = $enqueueConsumer;
        $this->enqueueMessage = $enqueueMessage;
    }

    /**
     * @param EnqueueConsumer $enqueueConsumer
     * @param EnqueueMessage $enqueueMessage
     * @return EnqueueAcknowledgementCallback
     */
    public static function createWithAutoAck(EnqueueConsumer $enqueueConsumer, EnqueueMessage $enqueueMessage, CachedConnectionFactory $connectionFactory, LoggingGateway $loggingGateway): self
    {
        return new self(true, $enqueueConsumer, $enqueueMessage, $connectionFactory, $loggingGateway);
    }

    /**
     * @param EnqueueConsumer $enqueueConsumer
     * @param EnqueueMessage $enqueueMessage
     * @return EnqueueAcknowledgementCallback
     */
    public static function createWithManualAck(EnqueueConsumer $enqueueConsumer, EnqueueMessage $enqueueMessage, CachedConnectionFactory $connectionFactory, LoggingGateway $loggingGateway): self
    {
        return new self(false, $enqueueConsumer, $enqueueMessage, $connectionFactory, $loggingGateway);
    }

    /**
     * @inheritDoc
     */
    public function isAutoAck(): bool
    {
        return $this->isAutoAck;
    }

    /**
     * @inheritDoc
     */
    public function disableAutoAck(): void
    {
        $this->isAutoAck = false;
    }

    /**
     * @inheritDoc
     */
    public function accept(): void
    {
        try {
            $this->enqueueConsumer->acknowledge($this->enqueueMessage);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to acknowledge message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     */
    public function reject(): void
    {
        try {
            $this->enqueueConsumer->reject($this->enqueueMessage);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to reject message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), exception: $exception);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     */
    public function requeue(): void
    {
        try {
            $this->enqueueConsumer->reject($this->enqueueMessage, true);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to requeue message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), exception: $exception);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }
}
