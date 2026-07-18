<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Sqs\SqsContext;
use Enqueue\Sqs\SqsDestination;
use Interop\Queue\Exception\InvalidMessageException;

/**
 * licence Apache-2.0
 */
final class SqsOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    private const MAX_ENTRIES_PER_BATCH_REQUEST = 10;
    private const BATCH_REQUEST_PAYLOAD_BUDGET_IN_BYTES = 204800;

    private SqsRequestDispatchPool $requestDispatchPool;

    public function __construct(
        CachedConnectionFactory $connectionFactory,
        private string $queueName,
        bool $autoDeclare,
        OutboundMessageConverter $outboundMessageConverter,
        ConversionService $conversionService,
        private AsyncPublishingRegistry $asyncPublishingRegistry,
        private bool $asyncPublishing = false,
        private ?int $asyncPublishingTimeout = null,
    ) {
        $this->requestDispatchPool = new SqsRequestDispatchPool();
        parent::__construct(
            $connectionFactory,
            new SqsDestination($queueName),
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
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();

        $context->declareQueue($context->createQueue($this->queueName));
    }

    public function handle(Message $message): void
    {
        /** @var SqsContext $context */
        $context = $this->createOutboundContext();

        $payload = $message->getPayload();
        $messagesToPublish = $payload instanceof BatchMessage
            ? array_map(fn (array $entry): Message => $this->convertBatchEntryToMessage($entry), $payload->getEntries())
            : [$message];

        if ($messagesToPublish === []) {
            return;
        }

        $awsSqsClient = $context->getAwsSqsClient();
        $sendRequestPromises = [];
        $trackedMessagesPerRequest = [];
        foreach ($this->buildBatchRequests($messagesToPublish, $context) as $batchRequest) {
            $requestArguments = $batchRequest['arguments'];
            $sendRequestPromises[] = $this->requestDispatchPool->dispatch(fn () => $awsSqsClient->sendMessageBatchAsync($requestArguments));
            $trackedMessagesPerRequest[] = $batchRequest['trackedMessages'];
        }

        $pendingDelivery = new SqsPendingDelivery($sendRequestPromises, $trackedMessagesPerRequest, $this->queueName);

        if ($this->asyncPublishing && $this->asyncPublishingRegistry->isScopeActive()) {
            $this->asyncPublishingRegistry->register($this->queueName, $pendingDelivery);

            return;
        }

        $deliveryResult = $pendingDelivery->awaitDelivery();
        if (! $deliveryResult->isSuccessful()) {
            throw AsyncPublishingFailedException::withFailedDeliveries($deliveryResult->getFailedDeliveries());
        }
    }

    /**
     * @param Message[] $messagesToPublish
     * @return array<int, array{arguments: array, trackedMessages: array<string, Message>}>
     */
    private function buildBatchRequests(array $messagesToPublish, SqsContext $context): array
    {
        /** @var SqsDestination $destination */
        $destination = $this->destination;
        $queueUrl = $context->getQueueUrl($destination);

        $batchRequests = [];
        $entries = [];
        $trackedMessages = [];
        $payloadSizeInBytes = 0;

        foreach ($messagesToPublish as $messageIndex => $messageToPublish) {
            $entry = $this->buildBatchEntry((string) $messageIndex, $messageToPublish, $context);
            $entrySizeInBytes = strlen($entry['MessageBody']) + strlen($entry['MessageAttributes']['Headers']['StringValue']);

            $currentBatchIsFull = count($entries) >= self::MAX_ENTRIES_PER_BATCH_REQUEST
                || ($entries !== [] && $payloadSizeInBytes + $entrySizeInBytes > self::BATCH_REQUEST_PAYLOAD_BUDGET_IN_BYTES);
            if ($currentBatchIsFull) {
                $batchRequests[] = $this->buildBatchRequest($destination, $queueUrl, $entries, $trackedMessages);
                $entries = [];
                $trackedMessages = [];
                $payloadSizeInBytes = 0;
            }

            $entries[] = $entry;
            $trackedMessages[$entry['Id']] = $messageToPublish;
            $payloadSizeInBytes += $entrySizeInBytes;
        }

        if ($entries !== []) {
            $batchRequests[] = $this->buildBatchRequest($destination, $queueUrl, $entries, $trackedMessages);
        }

        return $batchRequests;
    }

    private function buildBatchEntry(string $entryId, Message $messageToPublish, SqsContext $context): array
    {
        $outboundMessage = $this->prepareOutboundMessage($messageToPublish);
        $headers = $outboundMessage->getHeaders();
        $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

        $sqsMessage = $context->createMessage($outboundMessage->getPayload(), $headers, []);
        if (empty($sqsMessage->getBody())) {
            throw new InvalidMessageException('The message body must be a non-empty string.');
        }

        $entry = [
            'Id' => $entryId,
            'MessageBody' => $sqsMessage->getBody(),
            'MessageAttributes' => [
                'Headers' => [
                    'DataType' => 'String',
                    'StringValue' => json_encode([$sqsMessage->getHeaders(), $sqsMessage->getProperties()]),
                ],
            ],
        ];

        if ($outboundMessage->getDeliveryDelay()) {
            $entry['DelaySeconds'] = (int) ceil($outboundMessage->getDeliveryDelay() / 1000);
        }

        return $entry;
    }

    /**
     * @param array<string, Message> $trackedMessages
     * @return array{arguments: array, trackedMessages: array<string, Message>}
     */
    private function buildBatchRequest(SqsDestination $destination, string $queueUrl, array $entries, array $trackedMessages): array
    {
        $arguments = [
            '@region' => $destination->getRegion(),
            'QueueUrl' => $queueUrl,
            'Entries' => $entries,
        ];

        if ($this->asyncPublishingTimeout !== null) {
            $arguments['@http'] = ['timeout' => $this->asyncPublishingTimeout / 1000];
        }

        return ['arguments' => $arguments, 'trackedMessages' => $trackedMessages];
    }
}
