<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture;

use Ecotone\Messaging\Attribute\Converter;

class CustomConverter
{
    #[Converter]
    public function convertFrom(MessageWithCustomConverter $object): array
    {
        return [
            'foo' => $object->sensitiveProperty,
            'bar' => $object->nonSensitiveProperty,
        ];
    }

    #[Converter]
    public function convertTo(array $payload): MessageWithCustomConverter
    {
        return new MessageWithCustomConverter(
            sensitiveProperty: $payload['foo'],
            nonSensitiveProperty: $payload['bar'],
        );
    }
}
