<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\AsyncPublishing\FailedDelivery;
use Ecotone\Messaging\Channel\AsyncPublishing\PublishingFailedException;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Redis\RedisContext;
use Enqueue\Redis\RedisDestination;
use Enqueue\Redis\RedisMessage;
use Interop\Queue\Context;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class RedisOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    private const BATCH_PUBLISH_SCRIPT = <<<'LUA'
        local pushed = 0
        local immediateAmount = tonumber(ARGV[1])
        for argumentIndex = 2, immediateAmount + 1 do
            local result = redis.pcall("lpush", KEYS[1], ARGV[argumentIndex])
            if type(result) == "table" and result.err then
                return {pushed, result.err}
            end
            pushed = pushed + 1
        end
        local argumentIndex = immediateAmount + 2
        while argumentIndex <= #ARGV do
            local result = redis.pcall("zadd", KEYS[2], ARGV[argumentIndex], ARGV[argumentIndex + 1])
            if type(result) == "table" and result.err then
                return {pushed, result.err}
            end
            pushed = pushed + 1
            argumentIndex = argumentIndex + 2
        end
        return pushed
        LUA;

    public function __construct(
        CachedConnectionFactory $connectionFactory,
        private string $queueName,
        bool $autoDeclare,
        OutboundMessageConverter $outboundMessageConverter,
        ConversionService $conversionService,
        AsyncPublishingRegistry $asyncPublishingRegistry,
        bool $asyncPublishing = false,
    ) {
        parent::__construct(
            $connectionFactory,
            new RedisDestination($queueName),
            $autoDeclare,
            $outboundMessageConverter,
            $conversionService,
            $asyncPublishingRegistry,
            $asyncPublishing,
            $queueName,
        );
    }

    public function initialize(): void
    {
        /** @var RedisContext $context */
        $context = $this->connectionFactory->createContext();
        $context->createQueue($this->queueName);
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
        if (count($batchMessage) === 0) {
            return;
        }

        /** @var RedisContext $context */
        $immediatePayloads = [];
        $immediateMessages = [];
        $delayedEntries = [];
        $delayedMessages = [];
        foreach ($batchMessage->getEntries() as $entry) {
            $originalMessage = $this->convertBatchEntryToMessage($entry);
            $outboundMessage = $this->prepareOutboundMessage($originalMessage);
            $headers = $outboundMessage->getHeaders();
            $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

            /** @var RedisMessage $messageToSend */
            $messageToSend = $context->createMessage($outboundMessage->getPayload(), $headers, []);
            $messageToSend->setMessageId(Uuid::uuid4()->toString());
            $messageToSend->setHeader('attempts', 0);

            if ($outboundMessage->getTimeToLive()) {
                $messageToSend->setTimeToLive($outboundMessage->getTimeToLive());
                $messageToSend->setHeader('expires_at', time() + (int) ceil($outboundMessage->getTimeToLive() / 1000));
            }

            $payload = $context->getSerializer()->toString($messageToSend);

            if ($outboundMessage->getDeliveryDelay()) {
                $delayedEntries[] = ['score' => time() + $outboundMessage->getDeliveryDelay() / 1000, 'payload' => $payload];
                $delayedMessages[] = $originalMessage;
            } else {
                $immediatePayloads[] = $payload;
                $immediateMessages[] = $originalMessage;
            }
        }

        if (count($immediatePayloads) === 1 && $delayedEntries === []) {
            $queueLength = $context->getRedis()->lpush($this->queueName, $immediatePayloads[0]);
            if ($queueLength < 1) {
                throw new RuntimeException(sprintf('Redis did not confirm publishing message to queue %s.', $this->queueName));
            }

            return;
        }

        if ($immediatePayloads === [] && count($delayedEntries) === 1) {
            $addedMessages = $context->getRedis()->zadd($this->queueName . ':delayed', $delayedEntries[0]['payload'], $delayedEntries[0]['score']);
            if ($addedMessages !== 1) {
                throw new RuntimeException(sprintf('Redis did not confirm publishing delayed message to queue %s.', $this->queueName));
            }

            return;
        }

        $arguments = [count($immediatePayloads), ...$immediatePayloads];
        foreach ($delayedEntries as $delayedEntry) {
            $arguments[] = $delayedEntry['score'];
            $arguments[] = $delayedEntry['payload'];
        }

        $batchPublishResult = $context->getRedis()->eval(
            self::BATCH_PUBLISH_SCRIPT,
            [$this->queueName, $this->queueName . ':delayed'],
            $arguments,
        );

        if (is_array($batchPublishResult)) {
            $pushedMessages = (int) ($batchPublishResult[0] ?? 0);
            $failureReason = (string) ($batchPublishResult[1] ?? 'Redis rejected publishing');

            throw PublishingFailedException::withFailedDeliveries(array_map(
                fn (Message $unpublishedMessage): FailedDelivery => new FailedDelivery($unpublishedMessage, $failureReason, $this->queueName),
                array_slice([...$immediateMessages, ...$delayedMessages], $pushedMessages),
            ));
        }

        if ((int) $batchPublishResult !== count($batchMessage)) {
            throw new RuntimeException(sprintf(
                'Redis did not confirm publishing whole batch to queue %s. Expected %d published messages, got %s.',
                $this->queueName,
                count($batchMessage),
                var_export($batchPublishResult, true),
            ));
        }
    }
}
