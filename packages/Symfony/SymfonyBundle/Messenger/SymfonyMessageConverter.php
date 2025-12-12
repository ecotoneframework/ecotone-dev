<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * licence Apache-2.0
 */
final class SymfonyMessageConverter
{
    public const ECOTONE_SYMFONY_ACKNOWLEDGE_HEADER = 'ecotone.symfony.acknowledge';

    public function __construct(
        private HeaderMapper $headerMapper,
        private string $acknowledgeMode,
        private ConversionService $conversionService,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND
    ) {

    }

    public function convertToSymfonyMessage(Message $message, bool $withDelay): Envelope
    {
        $payload = $message->getPayload();
        $headers = MessageHeaders::unsetEnqueueMetadata($message->getHeaders()->headers());
        $headers = $this->headerMapper->mapFromMessageHeaders($headers, $this->conversionService);

        $type = Type::createFromVariable($payload);
        $contentType = MediaType::createApplicationXPHPWithTypeParameter($type->toString());
        if (! Type::createFromVariable($payload)->isClassOrInterface()) {
            $payload = new WrappedPayload($payload);
            if ($message->getHeaders()->hasContentType()) {
                $contentType = $message->getHeaders()->getContentType();
            }
        } else {
            $headers[MessageHeaders::TYPE_ID] = $type->toString();
        }

        $headers[MessageHeaders::CONTENT_TYPE] = $contentType->toString();
        $envelopeToSend = new Envelope($payload, [new MetadataStamp($headers)]);

        if ($message->getHeaders()->containsKey(MessageHeaders::DELIVERY_DELAY) && $withDelay) {
            $envelopeToSend = $envelopeToSend->with(new DelayStamp($message->getHeaders()->get(MessageHeaders::DELIVERY_DELAY)));
        }

        return $envelopeToSend;
    }

    public function convertFromSymfonyMessage(Envelope $symfonyEnvelope, TransportInterface $symfonyTransport): Message
    {
        $headers = $symfonyEnvelope->last(MetadataStamp::class)->getMetadata();

        $payload = $symfonyEnvelope->getMessage();
        if ($payload instanceof WrappedPayload) {
            $payload = $payload->getPayload();
        }
        $messageBuilder = MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($this->headerMapper->mapToMessageHeaders($headers, $this->conversionService));

        if (array_key_exists(MessageHeaders::CONTENT_TYPE, $headers)) {
            $messageBuilder = $messageBuilder
                ->setContentType(MediaType::parseMediaType($headers[MessageHeaders::CONTENT_TYPE]));
        }

        $amqpAcknowledgeCallback = SymfonyAcknowledgementCallback::createWithFailureStrategy(
            $symfonyTransport,
            $symfonyEnvelope,
            $this->finalFailureStrategy,
            $this->acknowledgeMode == SymfonyAcknowledgementCallback::AUTO_ACK
        );

        $messageBuilder = $messageBuilder
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, self::ECOTONE_SYMFONY_ACKNOWLEDGE_HEADER)
            ->setHeader(self::ECOTONE_SYMFONY_ACKNOWLEDGE_HEADER, $amqpAcknowledgeCallback);

        return $messageBuilder->build();
    }
}
