<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler;

use Closure;
use Ecotone\Messaging\Config\Container\Definition;

use function sprintf;
use function strrchr;
use function substr;

/**
 * Names the place a single expression is written, so a failure during its evaluation
 * can point back at the attribute, the target it fills and the method it is declared on.
 *
 * @link https://docs.ecotone.tech
 */
/**
 * licence Apache-2.0
 */
final class ExpressionLocation
{
    public function __construct(
        private string $attributeName,
        private string $target,
        private string $owner,
        private string $expression,
    ) {
    }

    public static function definitionForParameter(string $attributeClassName, ?string $parameterName, string $ownerClassName, ?string $ownerMethodName, string|Closure|null $expression): Definition
    {
        return self::definitionFor(self::attributeNameOf($attributeClassName), $parameterName === null ? '' : '$' . $parameterName, self::ownerOf($ownerClassName, $ownerMethodName), $expression);
    }

    public static function definitionForEndpointAttribute(string $attributeClassName, string $ownerClassName, ?string $ownerMethodName, string|Closure|null $expression): Definition
    {
        return self::definitionFor(self::attributeNameOf($attributeClassName), '', self::ownerOf($ownerClassName, $ownerMethodName), $expression);
    }

    public static function definitionForEndpointStep(string $stepName, string $target, string $endpointName, string $expression): Definition
    {
        return self::definitionFor($stepName, $target, sprintf("endpoint '%s'", $endpointName), $expression);
    }

    public function describeFailureWith(string $cause): string
    {
        return sprintf('%s failed. Expression: %s. %s', $this->describe(), $this->expression, $cause);
    }

    public function describe(): string
    {
        return $this->target === ''
            ? sprintf('%s in %s', $this->attributeName, $this->owner)
            : sprintf('%s on %s in %s', $this->attributeName, $this->target, $this->owner);
    }

    private static function definitionFor(string $attributeName, string $target, string $owner, string|Closure|null $expression): Definition
    {
        return new Definition(self::class, [
            $attributeName,
            $target,
            $owner,
            $expression instanceof Closure ? 'closure' : (string) $expression,
        ]);
    }

    private static function attributeNameOf(string $attributeClassName): string
    {
        $shortName = strrchr($attributeClassName, '\\');

        return sprintf('#[%s]', $shortName === false ? $attributeClassName : substr($shortName, 1));
    }

    private static function ownerOf(string $className, ?string $methodName): string
    {
        return $methodName === null ? $className : $className . '::' . $methodName;
    }
}
