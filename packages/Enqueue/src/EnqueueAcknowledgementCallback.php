<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Exception;
use Interop\Amqp\AmqpProducer;
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
     * @var FinalFailureStrategy
     */
    private $failureStrategy;
    /**
     * @var bool
     */
    private $isAutoAcked;
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
     * @param FinalFailureStrategy $failureStrategy
     * @param bool $isAutoAcked
     * @param EnqueueConsumer $enqueueConsumer
     * @param EnqueueMessage $enqueueMessage
     */
    private function __construct(FinalFailureStrategy $failureStrategy, bool $isAutoAcked, EnqueueConsumer $enqueueConsumer, EnqueueMessage $enqueueMessage, private CachedConnectionFactory $connectionFactory, private LoggingGateway $loggingGateway)
    {
        $this->failureStrategy = $failureStrategy;
        $this->isAutoAcked = $isAutoAcked;
        $this->enqueueConsumer = $enqueueConsumer;
        $this->enqueueMessage = $enqueueMessage;
    }

    /**
     * @param EnqueueConsumer $enqueueConsumer
     * @param EnqueueMessage $enqueueMessage
     * @param FinalFailureStrategy $finalFailureStrategy
     * @param bool $isAutoAcked
     * @return EnqueueAcknowledgementCallback
     */
    public static function create(EnqueueConsumer $enqueueConsumer, EnqueueMessage $enqueueMessage, CachedConnectionFactory $connectionFactory, LoggingGateway $loggingGateway, FinalFailureStrategy $finalFailureStrategy, bool $isAutoAcked): self
    {
        return new self($finalFailureStrategy, $isAutoAcked, $enqueueConsumer, $enqueueMessage, $connectionFactory, $loggingGateway);
    }

    /**
     * @inheritDoc
     */
    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->failureStrategy;
    }

    /**
     * @inheritDoc
     */
    public function isAutoAcked(): bool
    {
        return $this->isAutoAcked;
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
            $this->loggingGateway->info('Failed to reject message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     * Resends the message to the END of the queue by using MessageChannel.send()
     */
    public function resend(): void
    {
        try {
            /** @var AmqpProducer $producer */
            $producer = $this->connectionFactory->getProducer();
            $producer->send(
                $this->enqueueConsumer->getQueue(),
                $this->enqueueMessage
            );
            $this->enqueueConsumer->reject($this->enqueueMessage, false);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to requeue message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     * Releases the message to the BEGINNING of the queue by using reject with requeue=true
     */
    public function release(): void
    {
        try {
            $this->enqueueConsumer->reject($this->enqueueMessage, true);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to requeue message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }
}
