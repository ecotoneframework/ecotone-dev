<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;

use function is_scalar;

/**
 * licence Apache-2.0
 */
final class ArgumentTypeConversion
{
    public function __construct(private ConversionService $conversionService)
    {
    }

    public static function definition(): Definition
    {
        return new Definition(self::class, [new Reference(ConversionService::REFERENCE_NAME)]);
    }

    public function canConvert(mixed $value, Type $parameterType): bool
    {
        return $this->mediaTypeToConvertFrom($value, Type::createFromVariable($value), $parameterType) !== null;
    }

    public function convertWhenPossible(mixed $value, Type $parameterType): mixed
    {
        if ($parameterType->accepts($value)) {
            return $value;
        }

        $valueType = Type::createFromVariable($value);
        $valueMediaType = $this->mediaTypeToConvertFrom($value, $valueType, $parameterType);
        if ($valueMediaType === null) {
            return $value;
        }

        return $this->conversionService->convert(
            $value,
            $valueType,
            $valueMediaType,
            $parameterType,
            MediaType::createApplicationXPHP(),
        );
    }

    private function mediaTypeToConvertFrom(mixed $value, Type $valueType, Type $parameterType): ?MediaType
    {
        $phpMediaType = MediaType::createApplicationXPHP();
        if ($this->conversionService->canConvert($valueType, $phpMediaType, $parameterType, $phpMediaType)) {
            return $phpMediaType;
        }

        $fallbackMediaType = MediaType::parseMediaType(DefaultHeaderMapper::FALLBACK_HEADER_CONVERSION_MEDIA_TYPE);
        if (is_scalar($value) && $this->conversionService->canConvert($valueType, $fallbackMediaType, $parameterType, $phpMediaType)) {
            return $fallbackMediaType;
        }

        return null;
    }
}
