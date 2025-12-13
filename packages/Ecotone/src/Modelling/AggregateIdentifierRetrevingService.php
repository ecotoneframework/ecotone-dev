<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Enricher\PropertyPath;
use Ecotone\Messaging\Handler\Enricher\PropertyReaderAccessor;
use Ecotone\Messaging\Handler\ExpressionEvaluationService;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\AggregateIdMetadata;

/**
 * Class AggregateMessageConversionService
 * @package Ecotone\Modelling
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class AggregateIdentifierRetrevingService implements MessageProcessor
{
    /**
     * @param array<string, array<string, string|null>> $perClassIdentifierMappings
     */
    public function __construct(
        private string $aggregateClassName,
        private ConversionService $conversionService,
        private PropertyReaderAccessor $propertyReaderAccessor,
        private array $metadataIdentifierMapping,
        private array $identifierMapping,
        private ExpressionEvaluationService $expressionEvaluationService,
        private array $perClassIdentifierMappings,
    ) {

    }

    public function process(Message $message): Message
    {
        /** @TODO Ecotone 2.0 (remove) this. For backward compatibility because it's ran again when message is consumed from Queue e*/
        if ($this->messageContainsCorrectAggregateId($message)) {
            return $message;
        }

        $payload = $message->getPayload();
        $messageIdentifierMapping = $this->resolveMessageIdentifierMapping($message);

        if ($message->getHeaders()->containsKey(AggregateMessage::OVERRIDE_AGGREGATE_IDENTIFIER)) {
            $aggregateIds = $message->getHeaders()->get(AggregateMessage::OVERRIDE_AGGREGATE_IDENTIFIER);
            $aggregateIds = \is_array($aggregateIds) ? $aggregateIds : [\array_key_first($messageIdentifierMapping) => $aggregateIds];

            return MessageBuilder::fromMessage($message)
                ->setHeader(AggregateMessage::AGGREGATE_ID, AggregateIdResolver::resolveArrayOfIdentifiers($this->aggregateClassName, $aggregateIds))
                ->removeHeader(AggregateMessage::OVERRIDE_AGGREGATE_IDENTIFIER)
                ->build();
        }

        $aggregateIdentifiers = [];
        foreach ($messageIdentifierMapping as $aggregateIdentifierName => $aggregateIdentifierMappingName) {
            if ($aggregateIdentifierMappingName === null) {
                $aggregateIdentifiers[$aggregateIdentifierName] = null;
                continue;
            }

            $sourcePayload = ! \is_object($payload) && $message->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_INSTANCE)
                ? $message->getHeaders()->get(AggregateMessage::CALLED_AGGREGATE_INSTANCE)
                : $payload;
            $aggregateIdentifiers[$aggregateIdentifierName] =
                $this->propertyReaderAccessor->hasPropertyValue(PropertyPath::createWith($aggregateIdentifierMappingName), $sourcePayload)
                    ? $this->propertyReaderAccessor->getPropertyValue(PropertyPath::createWith($aggregateIdentifierMappingName), $sourcePayload)
                    : null;
        }
        $metadata = $message->getHeaders()->headers();
        foreach ($this->metadataIdentifierMapping as $identifierName => $headerName) {
            if (\array_key_exists($headerName, $metadata)) {
                $aggregateIdentifiers[$identifierName] = $metadata[$headerName];
            }
        }
        foreach ($this->identifierMapping as $identifierName => $expression) {
            $aggregateIdentifiers[$identifierName] = $this->expressionEvaluationService->evaluate($expression, [
                'headers' => $metadata,
                'payload' => $payload,
            ]);
        }

        if (! AggregateIdResolver::canResolveAggregateId($this->aggregateClassName, $aggregateIdentifiers)) {
            return $message;
        }

        return MessageBuilder::fromMessage($message)
            ->setHeader(AggregateMessage::AGGREGATE_ID, AggregateIdResolver::resolveArrayOfIdentifiers($this->aggregateClassName, $aggregateIdentifiers))
            ->build();
    }

    /**
     * Resolves the appropriate identifier mapping based on the payload type or TYPE_ID header.
     *
     * @return array<string, string|null>
     */
    private function resolveMessageIdentifierMapping(Message $message): array
    {
        $payload = $message->getPayload();

        if (\is_object($payload)) {
            $payloadClass = \get_class($payload);

            if (isset($this->perClassIdentifierMappings[$payloadClass])) {
                return $this->perClassIdentifierMappings[$payloadClass];
            }

            foreach (\array_keys($this->perClassIdentifierMappings) as $handledClassName) {
                if ($handledClassName !== '' && $payload instanceof $handledClassName) {
                    return $this->perClassIdentifierMappings[$handledClassName];
                }
            }
        }

        if ($message->getHeaders()->containsKey(MessageHeaders::TYPE_ID)) {
            $typeId = $message->getHeaders()->get(MessageHeaders::TYPE_ID);
            if (isset($this->perClassIdentifierMappings[$typeId])) {
                return $this->perClassIdentifierMappings[$typeId];
            }
        }

        return $this->perClassIdentifierMappings[''] ?? [];
    }

    private function messageContainsCorrectAggregateId(Message $message): bool
    {
        if (! $message->getHeaders()->containsKey(AggregateMessage::AGGREGATE_ID)) {
            return false;
        }

        $aggregateIdentifiers = AggregateIdMetadata::createFrom($message->getHeaders()->get(AggregateMessage::AGGREGATE_ID))->getIdentifiers();

        if ($this->metadataIdentifierMapping !== []) {
            return \array_keys($this->metadataIdentifierMapping) === \array_keys($aggregateIdentifiers);
        }

        $messageIdentifierMapping = $this->resolveMessageIdentifierMapping($message);
        return \array_keys($messageIdentifierMapping) === \array_keys($aggregateIdentifiers);
    }
}
