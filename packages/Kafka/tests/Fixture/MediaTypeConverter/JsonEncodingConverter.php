<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\MediaTypeConverter;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

#[MediaTypeConverter]
/**
 * licence Apache-2.0
 */
final class JsonEncodingConverter implements Converter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        return json_encode($source, JSON_THROW_ON_ERROR);
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return $targetMediaType->isCompatibleWith(MediaType::createApplicationJson());
    }
}
