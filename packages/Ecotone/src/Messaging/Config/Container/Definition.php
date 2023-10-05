<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;

class Definition
{
    public function __construct(protected string $className, protected array $constructorArguments = [])
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
}