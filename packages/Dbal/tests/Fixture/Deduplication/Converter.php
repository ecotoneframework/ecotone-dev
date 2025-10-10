<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

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
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        if ($targetMediaType->isCompatibleWith(MediaType::createApplicationJson())) {
            return json_encode(['order' => $source->order]);
        } else {
            return new OrderPlaced(json_decode($source, true)['order']);
        }
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return true;
    }
}
