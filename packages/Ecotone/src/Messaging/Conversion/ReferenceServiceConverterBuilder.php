<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use ReflectionMethod;

/**
 * Class ReferenceConverterBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ReferenceServiceConverterBuilder implements CompilableBuilder
{
    private function __construct(private string $referenceName, private string $methodName, private Type $sourceType, private Type $targetType)
    {
        Assert::isFalse($sourceType->isUnionType(), "Source type for converter cannot be union type, {$sourceType} given for {$referenceName}:{$methodName}.");
        Assert::isFalse($targetType->isUnionType(), "Source type for converter cannot be union type, {$targetType} given for {$referenceName}:{$methodName}.");
    }

    /**
     * @param string $referenceName
     * @param string $method
     * @param Type $sourceType
     * @param Type $targetType
     * @return ReferenceServiceConverterBuilder
     */
    public static function create(string $referenceName, string $method, Type $sourceType, Type $targetType): self
    {
        return new self($referenceName, $method, $sourceType, $targetType);
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(ReferenceServiceConverter::class, [
            new Reference($this->referenceName),
            $this->methodName,
            $this->sourceType,
            $this->targetType,
        ]);
    }
}
