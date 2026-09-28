<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Messaging\Config\Container\Definition;

use function sprintf;

/**
 * licence Enterprise
 */
final class DecisionModelHandler
{
    /**
     * @param Definition[] $modelLoaderDefinitions
     * @param class-string[] $ambiguouslyDuplicatedModelClasses
     */
    public function __construct(
        private readonly string $className,
        private readonly string $methodName,
        private readonly array $modelLoaderDefinitions,
        private readonly ?string $boundaryMethodName,
        private readonly bool $appendsItsResult,
        private readonly array $ambiguouslyDuplicatedModelClasses = [],
    ) {
    }

    public function withoutCompileTimeDefinitions(): Definition
    {
        return new Definition(self::class, [
            $this->className,
            $this->methodName,
            [],
            $this->boundaryMethodName,
            $this->appendsItsResult,
        ]);
    }

    public static function keyFor(string $className, string $methodName): string
    {
        return sprintf('%s::%s', $className, $methodName);
    }

    public function key(): string
    {
        return self::keyFor($this->className, $this->methodName);
    }

    public function matches(string $className, string $methodName): bool
    {
        return $this->className === $className && $this->methodName === $methodName;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function methodName(): string
    {
        return $this->methodName;
    }

    /**
     * @return Definition[]
     */
    public function modelLoaderDefinitions(): array
    {
        return $this->modelLoaderDefinitions;
    }

    public function loadsModelsBeforeInvocation(): bool
    {
        return $this->modelLoaderDefinitions !== [];
    }

    public function boundaryMethodName(): ?string
    {
        return $this->boundaryMethodName;
    }

    public function hasBoundaryMethod(): bool
    {
        return $this->boundaryMethodName !== null;
    }

    public function appendsItsResult(): bool
    {
        return $this->appendsItsResult;
    }

    /**
     * @return class-string[]
     */
    public function ambiguouslyDuplicatedModelClasses(): array
    {
        return $this->ambiguouslyDuplicatedModelClasses;
    }
}
