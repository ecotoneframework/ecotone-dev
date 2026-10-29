<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Api\Messaging\BatchMessage;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalContext;
use Ecotone\Dbal\Connection\DbalDestination;
use Ecotone\Dbal\Connection\DbalProducer;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Config\DatabaseTransaction\DatabaseTransactionLost;
use Interop\Queue\Context;
use Throwable;

/**
 * licence Apache-2.0
 */
class DbalOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function __construct(
        CachedConnectionFactory $connectionFactory,
        private string $queueName,
        bool $autoDeclare,
        OutboundMessageConverter $outboundMessageConverter,
        ConversionService $conversionService,
        MessageBuilder $messageBuilder,
        private EnqueueTableManager $tableManager,
        bool $batchPublishing = false,
    ) {
        parent::__construct(
            $connectionFactory,
            new DbalDestination($this->queueName),
            $autoDeclare,
            $outboundMessageConverter,
            $conversionService,
            $messageBuilder,
            $batchPublishing,
            $this->queueName,
        );
    }

    public function handle(Message $message): void
    {
        $sentInsideTransaction = $this->isInsideTransaction();
        try {
            parent::handle($message);
        } catch (Throwable $failure) {
            if ($sentInsideTransaction && self::isCausedByLostConnection($failure)) {
                throw DatabaseTransactionLost::whileSendingTo($this->queueName, $failure);
            }

            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    private function isInsideTransaction(): bool
    {
        $context = $this->connectionFactory->createContext();

        return $context instanceof DbalContext && $context->getDbalConnection()->isTransactionActive();
    }

    private static function isCausedByLostConnection(Throwable $failure): bool
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException) {
                return true;
            }
        }

        return false;
    }

    public function initialize(): void
    {
    }

    protected function sendSingleMessage(Message $message, Context $context): void
    {
        $this->handleBatch(
            BatchMessage::constructEmpty()->append($message->getPayload(), $message->getHeaders()->headers()),
            $context,
        );
    }

    protected function handleBatch(BatchMessage $batchMessage, Context $context): void
    {
        $messagesToSend = [];
        foreach ($batchMessage->entries as $entry) {
            $outboundMessage = $this->prepareOutboundMessageFromBatchEntry($entry);
            $headers = $outboundMessage->getHeaders();
            $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

            $messageToSend = $context->createMessage($outboundMessage->getPayload(), $headers, []);
            $messageToSend->setDeliveryDelay($outboundMessage->getDeliveryDelay());
            $messageToSend->setTimeToLive($outboundMessage->getTimeToLive());

            $messagesToSend[] = $messageToSend;
        }

        /** @var DbalProducer $producer */
        $producer = $context->createProducer();
        $producer->sendBatch($this->destination, $messagesToSend);
    }

    public function __toString(): string
    {
        return "dbal outbound: {$this->queueName}";
    }
}
