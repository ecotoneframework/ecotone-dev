<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Compatibility;

use Symfony\Component\DependencyInjection\Container;

/**
 * Compatibility layer for Symfony Container across different versions
 * 
 * licence Apache-2.0
 */
final class ContainerCompatibility
{
    /**
     * Check if the Container::getParameter method has a return type declaration
     * In Symfony 5.x, it doesn't have a return type
     * In Symfony 7.x, it has a return type of UnitEnum|array|string|int|float|bool|null
     */
    public static function hasGetParameterReturnType(): bool
    {
        $reflectionMethod = new \ReflectionMethod(Container::class, 'getParameter');
        return $reflectionMethod->hasReturnType();
    }

    /**
     * Get the return type of Container::getParameter method as a string
     * Returns empty string if no return type is defined
     */
    public static function getParameterReturnTypeAsString(): string
    {
        if (!self::hasGetParameterReturnType()) {
            return '';
        }

        $reflectionMethod = new \ReflectionMethod(Container::class, 'getParameter');
        $returnType = $reflectionMethod->getReturnType();
        
        if ($returnType === null) {
            return '';
        }
        
        return $returnType->__toString();
    }
}
