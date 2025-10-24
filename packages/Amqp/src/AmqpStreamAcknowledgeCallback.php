<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Enqueue\AmqpLib\AmqpContext;
use Exception;
use PhpAmqpLib\Message\AMQPMessage as PhpAmqpLibMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * AMQP Stream Acknowledge Callback
 * licence Enterprise
 */
class AmqpStreamAcknowledgeCallback implements AcknowledgementCallback
{
    private function __construct(
        private PhpAmqpLibMessage $amqpMessage,
        private LoggingGateway $loggingGateway,
        private CachedConnectionFactory $connectionFactory,
        private FinalFailureStrategy $failureStrategy,
        private bool $isAutoAcked,
        private ConsumerPositionTracker $positionTracker,
        private string $consumerId,
        private ?string $streamOffset,
        private CancellableAmqpStreamConsumer $streamConsumer,
        private string $queueName,
        private CachedConnectionFactory $publisherConnectionFactory,
        private BatchCommitCoordinator $batchCommitCoordinator
    ) {
    }

    public static function create(
        PhpAmqpLibMessage $amqpMessage,
        LoggingGateway $loggingGateway,
        CachedConnectionFactory $connectionFactory,
        FinalFailureStrategy $failureStrategy,
        bool $isAutoAcked,
        ConsumerPositionTracker $positionTracker,
        string $consumerId,
        ?string $streamOffset,
        CancellableAmqpStreamConsumer $streamConsumer,
        string $queueName,
        CachedConnectionFactory $publisherConnectionFactory,
        BatchCommitCoordinator $batchCommitCoordinator
    ): self {
        return new self($amqpMessage, $loggingGateway, $connectionFactory, $failureStrategy, $isAutoAcked, $positionTracker, $consumerId, $streamOffset, $streamConsumer, $queueName, $publisherConnectionFactory, $batchCommitCoordinator);
    }

    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->failureStrategy;
    }

    public function isAutoAcked(): bool
    {
        return $this->isAutoAcked;
    }

    public function accept(): void
    {
        $this->commitPosition();
    }

    public function reject(): void
    {
        $this->commitPosition();
    }

    public function resend(): void
    {
        try {
            // Get the message body and properties
            $body = $this->amqpMessage->getBody();
            $properties = $this->amqpMessage->get_properties();

            /** @var AMQPTable $amqpHeaders */
            $amqpHeaders = $properties['application_headers'] ?? null;
            $headers = $amqpHeaders ? $amqpHeaders->getNativeData() : [];

            // Remove stream-specific headers that shouldn't be resent
            unset($headers['x-stream-offset']);

            // Remove application_headers from properties as we'll pass them separately
            unset($properties['application_headers']);

            $resendMessage = new PhpAmqpLibMessage($body, $properties);
            if (!empty($headers)) {
                $resendMessage->set('application_headers', new AMQPTable($headers));
            }

            /** @var AmqpContext $publisherContext */
            $publisherContext = $this->publisherConnectionFactory->createContext();
            $publisherLibChannel = $publisherContext->getLibChannel();

            $publisherLibChannel->basic_publish($resendMessage, '', $this->queueName);
            $publisherLibChannel->wait_for_pending_acks(5);

            $this->accept();

            // Cancel the consumer to force it to restart and see the new message
            // This is necessary because RabbitMQ streams don't automatically deliver
            // messages added after the consumer has caught up
            $this->streamConsumer->cancelStreamConsumer();
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to resend AMQP stream message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    public function release(): void
    {
        $this->streamConsumer->cancelStreamConsumer();
    }

    private function commitPosition(): void
    {
        if ($this->streamOffset === null) {
            return;
        }

        $this->batchCommitCoordinator->recordMessageProcessed($this->streamOffset);
        $this->batchCommitCoordinator->commitPendingAndReset();
    }
}
