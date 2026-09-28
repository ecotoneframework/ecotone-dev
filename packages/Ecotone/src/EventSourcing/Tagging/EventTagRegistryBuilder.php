<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Messaging\Config\ConfigurationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Stringable;

use function array_map;
use function array_unique;
use function array_values;
use function in_array;
use function is_a;
use function preg_match_all;
use function sprintf;
use function str_contains;

/**
 * licence Enterprise
 */
final class EventTagRegistryBuilder
{
    public static function buildFrom(AnnotationFinder $annotationFinder): EventTagRegistry
    {
        return EventTagRegistry::createWith(self::buildRawDefinitions($annotationFinder));
    }

    /**
     * @return array<class-string, array<array{kind: string, name: string, member: ?string, value: ?string}>>
     */
    public static function buildRawDefinitions(AnnotationFinder $annotationFinder): array
    {
        $classNames = array_values(array_unique([
            ...$annotationFinder->findAnnotatedClasses(EventTag::class),
            ...$annotationFinder->findClassesWithAnnotatedProperties(EventTag::class),
            ...array_map(
                static fn ($annotatedMethod): string => $annotatedMethod->getClassName(),
                $annotationFinder->findAnnotatedMethods(EventTag::class),
            ),
        ]));

        $definitions = [];
        foreach ($classNames as $className) {
            $definitions[$className] = self::buildEntriesFor($className);
        }

        return $definitions;
    }

    /**
     * @return array<array{kind: string, name: string, member: ?string, value: ?string}>
     */
    private static function buildEntriesFor(string $className): array
    {
        $reflectionClass = new ReflectionClass($className);
        $entries = [];

        foreach ($reflectionClass->getAttributes(EventTag::class) as $classAttribute) {
            /** @var EventTag $eventTag */
            $eventTag = $classAttribute->newInstance();

            if ($eventTag->value === null) {
                throw ConfigurationException::create(sprintf(
                    "Class-level #[EventTag] on %s must declare a literal 'value:'.",
                    $className
                ));
            }

            $entries[] = ['kind' => 'literal', 'name' => $eventTag->name, 'member' => null, 'value' => $eventTag->value];
        }

        foreach ($reflectionClass->getProperties() as $property) {
            foreach ($property->getAttributes(EventTag::class) as $propertyAttribute) {
                /** @var EventTag $eventTag */
                $eventTag = $propertyAttribute->newInstance();

                self::assertPropertyTypeIsTaggable($property, $eventTag->name);

                $entries[] = ['kind' => 'property', 'name' => $eventTag->name, 'member' => $property->getName(), 'value' => null];
            }
        }

        foreach ($reflectionClass->getMethods() as $method) {
            foreach ($method->getAttributes(EventTag::class) as $methodAttribute) {
                /** @var EventTag $eventTag */
                $eventTag = $methodAttribute->newInstance();

                if (! $method->isPublic()) {
                    throw ConfigurationException::create(sprintf(
                        '#[EventTag] on method %s::%s must be placed on a public method.',
                        $className,
                        $method->getName()
                    ));
                }

                $entries[] = ['kind' => 'method', 'name' => $eventTag->name, 'member' => $method->getName(), 'value' => null];
            }
        }

        foreach ($entries as $entry) {
            self::assertTagNameFitsTheTagTables($className, $entry['name']);
        }

        return $entries;
    }

    private static function assertTagNameFitsTheTagTables(string $className, string $tagName): void
    {
        if ($tagName === '' || str_contains($tagName, "\0") || preg_match_all('/./su', $tagName) > 100) {
            throw ConfigurationException::create(sprintf(
                "#[EventTag] name '%s' on %s must be a non-empty name of at most 100 characters without NUL bytes -- it is stored in the tag tables' tag_name column.",
                $tagName,
                $className,
            ));
        }
    }

    private static function assertPropertyTypeIsTaggable(ReflectionProperty $property, string $tagName): void
    {
        $type = $property->getType();

        if ($type === null) {
            return;
        }

        $namedTypes = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

        foreach ($namedTypes as $namedType) {
            if (! $namedType instanceof ReflectionNamedType) {
                continue;
            }

            $name = $namedType->getName();

            if ($name === 'null') {
                continue;
            }

            if ($namedType->isBuiltin()) {
                if (! in_array($name, ['int', 'string', 'float', 'bool', 'array'], true)) {
                    throw ConfigurationException::create(sprintf(
                        "Tag '%s' on %s::\$%s must be typed scalar, Stringable or an array of those, got '%s'.",
                        $tagName,
                        $property->getDeclaringClass()->getName(),
                        $property->getName(),
                        $name
                    ));
                }

                continue;
            }

            if (! is_a($name, Stringable::class, true)) {
                throw ConfigurationException::create(sprintf(
                    "Tag '%s' on %s::\$%s must be typed scalar, Stringable or an array of those, got '%s'.",
                    $tagName,
                    $property->getDeclaringClass()->getName(),
                    $property->getName(),
                    $name
                ));
            }
        }
    }
}
