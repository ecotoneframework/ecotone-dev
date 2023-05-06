<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class SymfonyMessengerMessageChannel implements PollableChannel
{
    public const ECOTONE_SYMFONY_ACKNOWLEDGE_HEADER = 'ecotone.symfony.acknowledge';

    public function __construct(
        private TransportInterface $symfonyTransport,
        private HeaderMapper $headerMapper,
        private string $acknowledgeMode
    ) {

    }

    public function send(Message $message): void
    {
        $payload = $message->getPayload();
        $headers = MessageHeaders::unsetEnqueueMetadata($message->getHeaders()->headers());
        $headers = $this->headerMapper->mapFromMessageHeaders($headers);

        $type = TypeDescriptor::createFromVariable($payload);
        $contentType = MediaType::createApplicationXPHPWithTypeParameter($type->toString());
        if (! TypeDescriptor::createFromVariable($payload)->isClassOrInterface()) {
            $payload = new WrappedPayload($payload);
            if ($message->getHeaders()->hasContentType()) {
                $contentType = $message->getHeaders()->getContentType();
            }
        } else {
            $headers[MessageHeaders::TYPE_ID] = $type->toString();
        }

        $headers[MessageHeaders::CONTENT_TYPE] = $contentType->toString();
        $envelopeToSend = new Envelope($payload, [new MetadataStamp($headers)]);

        if ($message->getHeaders()->containsKey(MessageHeaders::DELIVERY_DELAY)) {
            $envelopeToSend = $envelopeToSend->with(new DelayStamp($message->getHeaders()->get(MessageHeaders::DELIVERY_DELAY)));
        }

        $this->symfonyTransport->send($envelopeToSend);
    }

    public function receive(): ?Message
    {
        /** @var Envelope[] $symfonyEnvelope */
        $symfonyEnvelope = $this->symfonyTransport->get();

        if ($symfonyEnvelope === []) {
            return null;
        }

        Assert::isTrue(count($symfonyEnvelope) === 1, 'Symfony messenger transport should be configured to return only one message at a time');
        $symfonyEnvelope = $symfonyEnvelope[0];

        $headers = $symfonyEnvelope->last(MetadataStamp::class)->getMetadata();

        $payload = $symfonyEnvelope->getMessage();
        if ($payload instanceof WrappedPayload) {
            $payload = $payload->getPayload();
        }
        $messageBuilder = MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($this->headerMapper->mapToMessageHeaders($headers));

        if (array_key_exists(MessageHeaders::CONTENT_TYPE, $headers)) {
            $messageBuilder = $messageBuilder
                ->setContentType(MediaType::parseMediaType($headers[MessageHeaders::CONTENT_TYPE]));
        }

        if (in_array($this->acknowledgeMode, [SymfonyAcknowledgementCallback::AUTO_ACK, SymfonyAcknowledgementCallback::MANUAL_ACK])) {
            if ($this->acknowledgeMode == SymfonyAcknowledgementCallback::AUTO_ACK) {
                $amqpAcknowledgeCallback = SymfonyAcknowledgementCallback::createWithAutoAck(
                    $this->symfonyTransport,
                    $symfonyEnvelope
                );
            } else {
                $amqpAcknowledgeCallback = SymfonyAcknowledgementCallback::createWithManualAck(
                    $this->symfonyTransport,
                    $symfonyEnvelope
                );
            }

            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, self::ECOTONE_SYMFONY_ACKNOWLEDGE_HEADER)
                ->setHeader(self::ECOTONE_SYMFONY_ACKNOWLEDGE_HEADER, $amqpAcknowledgeCallback);
        }

        return $messageBuilder->build();
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }
}
