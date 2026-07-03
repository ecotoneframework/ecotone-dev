<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
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
            redis.call("lpush", KEYS[1], ARGV[argumentIndex])
            pushed = pushed + 1
        end
        local argumentIndex = immediateAmount + 2
        while argumentIndex <= #ARGV do
            redis.call("zadd", KEYS[2], ARGV[argumentIndex], ARGV[argumentIndex + 1])
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
        $delayedEntries = [];
        foreach ($batchMessage->getEntries() as $entry) {
            $outboundMessage = $this->prepareOutboundMessage($this->convertBatchEntryToMessage($entry));
            $headers = $outboundMessage->getHeaders();
            $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

            /** @var RedisMessage $messageToSend */
            $messageToSend = $context->createMessage($outboundMessage->getPayload(), $headers, []);
            $messageToSend->setMessageId(Uuid::uuid4()->toString());
            $messageToSend->setHeader('attempts', 0);

            if ($outboundMessage->getTimeToLive()) {
                $messageToSend->setTimeToLive($outboundMessage->getTimeToLive());
                $messageToSend->setHeader('expires_at', time() + $messageToSend->getTimeToLive());
            }

            $payload = $context->getSerializer()->toString($messageToSend);

            if ($outboundMessage->getDeliveryDelay()) {
                $delayedEntries[] = ['score' => time() + $outboundMessage->getDeliveryDelay() / 1000, 'payload' => $payload];
            } else {
                $immediatePayloads[] = $payload;
            }
        }

        $arguments = [count($immediatePayloads), ...$immediatePayloads];
        foreach ($delayedEntries as $delayedEntry) {
            $arguments[] = $delayedEntry['score'];
            $arguments[] = $delayedEntry['payload'];
        }

        $pushedMessages = $context->getRedis()->eval(
            self::BATCH_PUBLISH_SCRIPT,
            [$this->queueName, $this->queueName . ':delayed'],
            $arguments,
        );

        if ($pushedMessages !== count($batchMessage)) {
            throw new RuntimeException(sprintf(
                'Redis did not confirm publishing whole batch to queue %s. Expected %d published messages, got %s.',
                $this->queueName,
                count($batchMessage),
                var_export($pushedMessages, true),
            ));
        }
    }
}
