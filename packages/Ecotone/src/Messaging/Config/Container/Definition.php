<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;

class Definition
{
    private bool $isLazy = false;

    public function __construct(protected string $className, protected array $constructorArguments = [], protected string $factoryMethod = '')
    {
    }

    public static function fromType(Type $type): self
    {
        $typeDescriptorArgument = $type instanceof UnionTypeDescriptor ? $type->getUnionTypes() : $type->toString();

        return new self(\get_class($type), [$typeDescriptorArgument]);
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getConstructorArguments(): array
    {
        return $this->constructorArguments;
    }

    public function getFactory(): string
    {
        return $this->factoryMethod;
    }

    public function setFactory(string $factoryMethod): self
    {
        $this->factoryMethod = $factoryMethod;
        return $this;
    }

    public function lazy(bool $isLazy = true): self
    {
        $this->isLazy = $isLazy;
        return $this;
    }

    public function islLazy(): bool
    {
        return $this->isLazy;
    }
}