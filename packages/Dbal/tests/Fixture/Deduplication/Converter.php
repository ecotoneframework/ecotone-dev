<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\TypeDescriptor;

use function json_decode;
use function json_encode;

#[MediaTypeConverter]
/**
 * licence Apache-2.0
 */
final class Converter implements \Ecotone\Messaging\Conversion\Converter
{
    /**
     * @param OrderPlaced|string $source
     */
    public function convert($source, TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType)
    {
        if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson())) {
            return json_encode(['order' => $source->order]);
        } else {
            return new OrderPlaced(json_decode($source, true)['order']);
        }
    }

    public function matches(TypeDescriptor $sourceType, MediaType $sourceMediaType, TypeDescriptor $targetType, MediaType $targetMediaType): bool
    {
        return true;
    }
}
