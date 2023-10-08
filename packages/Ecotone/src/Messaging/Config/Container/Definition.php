<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\UnionTypeDescriptor;

use function get_class;

class Definition
{
    private bool $isLazy = false;
    /**
     * @var MethodCall[]
     */
    private array $methodCalls = [];

    public function __construct(protected string $className, protected array $constructorArguments = [], protected string|array $factory = '')
    {
    }

    public static function fromType(Type $type): self
    {
        $typeDescriptorArgument = $type instanceof UnionTypeDescriptor ? $type->getUnionTypes() : $type->toString();

        return new self(get_class($type), [$typeDescriptorArgument]);
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getConstructorArguments(): array
    {
        return $this->constructorArguments;
    }

    public function getArgument(int $index): mixed
    {
        return $this->constructorArguments[$index];
    }

    public function setArgument(int $index, mixed $argument): self
    {
        $this->constructorArguments[$index] = $argument;
        return $this;
    }

    public function getFactory(): array
    {
        if (is_string($this->factory)) {
            return [$this->className, $this->factory];
        }
        return $this->factory;
    }

    public function hasFactory(): bool
    {
        return !empty($this->factory);
    }

    public function setFactory(string|array $factory): self
    {
        $this->factory = $factory;
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

    public function addMethodCall(string $string, array $array)
    {
        $this->methodCalls[] = new MethodCall($string, $array);
    }

    /**
     * @return MethodCall[]
     */
    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }
}

/**
 * @internal
 */
class MethodCall
{
    public function __construct(private string $methodName, private array $arguments)
    {
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }
}
