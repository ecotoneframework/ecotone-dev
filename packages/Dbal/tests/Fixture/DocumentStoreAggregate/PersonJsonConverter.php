<?php

namespace Test\Ecotone\Dbal\Fixture\DocumentStoreAggregate;

use Ecotone\Messaging\Attribute\MediaTypeConverter;
use Ecotone\Messaging\Conversion\Converter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;

use function json_decode;
use function json_encode;

#[MediaTypeConverter]
/**
 * licence Apache-2.0
 */
final class PersonJsonConverter implements Converter
{
    public function convert($source, Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType)
    {
        if ($sourceMediaType->isCompatibleWith(MediaType::createApplicationXPHP())) {
            /** @var Person $source */
            return json_encode([
                'personId' => $source->getPersonId(),
                'name' => $source->getName(),
            ]);
        }

        $data = json_decode($source, true);
        return Person::register(new RegisterPerson($data['personId'], $data['name']));
    }

    public function matches(Type $sourceType, MediaType $sourceMediaType, Type $targetType, MediaType $targetMediaType): bool
    {
        return ($sourceType->getTypeHint() === Person::class
                && $targetMediaType->isCompatibleWith(MediaType::createApplicationJson()))
            || ($sourceMediaType->isCompatibleWith(MediaType::createApplicationJson())
                && $targetType->getTypeHint() === Person::class);
    }
}
