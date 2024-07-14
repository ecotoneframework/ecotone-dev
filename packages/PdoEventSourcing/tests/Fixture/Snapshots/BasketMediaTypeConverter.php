<?php

namespace Test\Ecotone\EventSourcing\Fixture\Snapshots;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\TypeDescriptor;

use function json_decode;
use function json_encode;

use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;

#[MediaTypeConverter]
/**
 * licence Apache-2.0
 */
final class BasketMediaTypeConverter implements Converter
{
    /**
     * @param Basket $source
     */
    public function convert($source, TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType)
    {
        if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson())) {
            return json_encode($source->toArray());
        }

        return Basket::fromArray(json_decode($source, true));
    }

    public function matches(TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === Basket::class || $targetType->getTypeHint() === Basket::class;
    }
}
