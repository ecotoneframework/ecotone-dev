<?php

declare(strict_types=1);

namespace Ecotone\Laravel\Queue;

use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessage;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Contracts\Queue\Queue;

use Illuminate\Queue\SyncQueue;

use function json_decode;
use function json_encode;

/**
 * licence Apache-2.0
 */
final class LaravelQueueMessageChannel implements PollableChannel
{
    public const ECOTONE_LARAVEL_ACKNOWLEDGE_HEADER = 'ecotone.laravel.acknowledge';

    private const PAYLOAD = 'payload';
    private const HEADERS = 'headers';

    public function __construct(
        private Factory $queueFactory,
        private string $connectionName,
        private string $queueName,
        private string $acknowledgeMode,
        private OutboundMessageConverter $outboundMessageConverter,
        private ConversionService $conversionService
    ) {

    }

    public function send(Message $message): void
    {
        $connection = $this->getConnection();

        $outboundMessage = $this->outboundMessageConverter->prepare($message, $this->conversionService);
        $jobName = $this->prepareJobName($message);
        $jobPayload = $this->prepareJobPayload($outboundMessage);

        if ($outboundMessage->getDeliveryDelay()) {
            $connection->laterOn(
                $this->queueName,
                ceil($outboundMessage->getDeliveryDelay() / 1000),
                $jobName,
                $jobPayload
            );

            return;
        }

        Assert::isTrue(! $connection instanceof SyncQueue, 'Sync mode is not supported for Laravel Queue Message Channel.');
        $connection->pushOn(
            $this->queueName,
            $jobName,
            $jobPayload
        );
    }

    public function receive(): ?Message
    {
        $connection = $this->getConnection();

        $job = $connection->pop($this->queueName);

        if (! $job) {
            return null;
        }

        $message = json_decode($job->getRawBody(), true, 512, JSON_THROW_ON_ERROR);
        $message = json_decode($message['data'], true, 512, JSON_THROW_ON_ERROR);
        $messageBuilder = MessageBuilder::withPayload($message[self::PAYLOAD])
            ->setMultipleHeaders($message[self::HEADERS]);

        if (in_array($this->acknowledgeMode, [LaravelQueueAcknowledgementCallback::MANUAL_ACK, LaravelQueueAcknowledgementCallback::AUTO_ACK])) {
            $messageBuilder = $messageBuilder
                ->setHeader(
                    MessageHeaders::CONSUMER_ACK_HEADER_LOCATION,
                    self::ECOTONE_LARAVEL_ACKNOWLEDGE_HEADER
                );
        }

        if ($this->acknowledgeMode === LaravelQueueAcknowledgementCallback::MANUAL_ACK) {
            $messageBuilder = $messageBuilder
                ->setHeader(
                    self::ECOTONE_LARAVEL_ACKNOWLEDGE_HEADER,
                    LaravelQueueAcknowledgementCallback::createWithManualAck($job)
                );
        } elseif ($this->acknowledgeMode === LaravelQueueAcknowledgementCallback::AUTO_ACK) {
            $messageBuilder = $messageBuilder
                ->setHeader(
                    self::ECOTONE_LARAVEL_ACKNOWLEDGE_HEADER,
                    LaravelQueueAcknowledgementCallback::createWithAutoAck($job)
                );
        }

        return $messageBuilder
            ->build();
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }

    private function getConnection(): Queue
    {
        return $this->queueFactory->connection($this->connectionName);
    }

    private function prepareJobPayload(OutboundMessage $outboundMessage): string|false
    {
        return json_encode([
            self::PAYLOAD => $outboundMessage->getPayload(),
            self::HEADERS => array_merge(
                $outboundMessage->getHeaders(),
                [MessageHeaders::CONTENT_TYPE => $outboundMessage->getContentType()]
            ),
        ], JSON_THROW_ON_ERROR);
    }

    private function prepareJobName(Message $message): mixed
    {
        return $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP)
            ? $message->getHeaders()->get(MessageHeaders::ROUTING_SLIP)
            : 'ecotone';
    }
}
