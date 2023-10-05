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

//    public function instance(): object
//    {
//        return new $this->className(...$this->constructorArguments);
//    }
//
//    protected function resolveArguments(array $argumentsToResolve): array
//    {
//        $arguments = [];
//        foreach ($argumentsToResolve as $argument) {
//            if ($argument instanceof Definition) {
//                $arguments[] = $argument->instance();
//            } elseif (is_array($argument)) {
//                $arguments[] = $this->resolveArguments($argument);
//            }
//        }
//
//        return $arguments;
//    }
    public function getConstructorArguments(): array
    {
        return $this->constructorArguments;
    }
}