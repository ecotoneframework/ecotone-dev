<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\Messaging\Config\ConfigurationException;
use Stringable;

use function get_debug_type;
use function is_array;
use function is_scalar;
use function rtrim;
use function sprintf;
use function strlen;

/**
 * licence Enterprise
 */
final class EventTagValueNormalizer
{
    /**
     * @return string[]
     */
    public static function normalize(string $tagName, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                $result = [...$result, ...self::normalize($tagName, $item)];
            }

            return $result;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return [self::validate($tagName, (string) $value)];
        }

        throw ConfigurationException::create(sprintf(
            "Tag '%s' value must be scalar, Stringable, null or an array of those, got %s.",
            $tagName,
            get_debug_type($value)
        ));
    }

    private static function validate(string $tagName, string $value): string
    {
        if ($value === '') {
            throw ConfigurationException::create(sprintf("Tag '%s' value cannot be empty.", $tagName));
        }

        if (strlen($value) > 255) {
            throw ConfigurationException::create(sprintf("Tag '%s' value cannot be longer than 255 characters, got %d.", $tagName, strlen($value)));
        }

        if (rtrim($value) !== $value) {
            throw ConfigurationException::create(sprintf("Tag '%s' value '%s' cannot have trailing whitespace.", $tagName, $value));
        }

        return $value;
    }
}
