<?php

namespace Test\Ecotone\EventSourcing\Fixture\Snapshots;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

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
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson())) {
            return json_encode($source->toArray());
        }

        return Basket::fromArray(json_decode($source, true));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $sourceType->getTypeHint() === Basket::class || $targetType->getTypeHint() === Basket::class;
    }
}
