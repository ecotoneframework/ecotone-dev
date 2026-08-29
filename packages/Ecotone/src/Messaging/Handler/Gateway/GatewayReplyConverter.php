<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gateway;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Future;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageConverter\MessageConverter;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;
use Generator;

/**
 * licence Apache-2.0
 */
class GatewayReplyConverter
{
    /**
     * @param MessageConverter[] $messageConverters
     */
    public function __construct(
        private ConversionService $conversionService,
        private string $interfaceToCallName,
        private ?Type $returnType,
        private array $messageConverters
    ) {
    }

    public function convert(mixed $result, ?MediaType $replyContentType)
    {
        foreach ($this->messageConverters as $messageConverter) {
            $reply = $messageConverter->fromMessage(
                $result,
                $this->returnType
            );

            if ($reply) {
                return $reply;
            }
        }

        $isMessage = $result instanceof Message;
        $data = $isMessage ? $result->getPayload() : $result;
        $sourceMediaType = MediaType::createApplicationXPHP();
        $sourceType = Type::createFromVariable($data);

        if ($isMessage) {
            if ($result->getHeaders()->hasContentType()) {
                $sourceMediaType = $result->getHeaders()->getContentType();

                if ($sourceMediaType->hasTypeParameter()) {
                    $sourceType = $sourceMediaType->getTypeParameter();
                }
            }
        }

        if (! $replyContentType) {
            if ($data instanceof Generator) {
                return $this->yieldResults($data, $this->collectionElementType());
            }

            if (is_array($data) && ! $sourceType instanceof Type\GenericType && ($elementType = $this->collectionElementType())) {
                $converted = [];
                foreach ($data as $key => $item) {
                    $converted[$key] = $this->convertElementIfNeeded($item, $elementType);
                }

                return $converted;
            }

            if (! $this->returnType->isMessage() && ! $sourceType->isCompatibleWith($this->returnType)) {
                if ($this->conversionService->canConvert($sourceType, $sourceMediaType, $this->returnType, MediaType::createApplicationXPHP())) {
                    return $this->conversionService->convert($data, $sourceType, $sourceMediaType, $this->returnType, MediaType::createApplicationXPHP());
                }
            }

            if ($result instanceof Future) {
                return $result;
            }

            if ($this->returnType->isMessage()) {
                return $result;
            }

            return $result->getPayload();
        }

        if (! $sourceMediaType->isCompatibleWith($replyContentType) || ($replyContentType->hasTypeParameter() && $replyContentType->getTypeParameter()->isIterable())) {
            $targetType = $replyContentType->hasTypeParameter() ? $replyContentType->getTypeParameter() : Type::anything();
            if (! $this->conversionService->canConvert(
                $sourceType,
                $sourceMediaType,
                $targetType,
                $replyContentType
            )) {
                throw InvalidArgumentException::create("Lack of converter for {$this->interfaceToCallName} can't convert reply {$sourceMediaType}:{$sourceType} to {$replyContentType}:{$targetType}");
            }

            $data = $this->conversionService->convert(
                $data,
                $sourceType,
                $sourceMediaType,
                $targetType,
                $replyContentType
            );
        }

        if ($this->returnType->isMessage()) {
            return MessageBuilder::fromMessage($result)
                        ->setContentType($replyContentType)
                        ->setPayload($data)
                        ->build();
        }

        return $data;
    }

    private function yieldResults(Generator $data, ?Type $expectedType): Generator
    {
        foreach ($data as $result) {
            yield $expectedType !== null ? $this->convertElementIfNeeded($result, $expectedType) : $result;
        }
    }

    private function collectionElementType(): ?Type
    {
        if ($this->returnType instanceof Type\GenericType && count($this->returnType->genericTypes) === 1) {
            return $this->returnType->genericTypes[0];
        }

        return null;
    }

    private function convertElementIfNeeded(mixed $result, Type $expectedType): mixed
    {
        if ($expectedType->accepts($result)) {
            return $result;
        }

        return $this->conversionService->convert($result, Type::createFromVariable($result), MediaType::createApplicationXPHP(), $expectedType, MediaType::createApplicationXPHP());
    }
}
