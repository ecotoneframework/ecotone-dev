<?php

namespace Ecotone\Messaging\Config\Container;

class Definition
{
    /**
     * @var MethodCall[]
     */
    private array $methodCalls = [];

    /**
     * @param array<string|int, mixed> $constructorArguments
     */
    public function __construct(protected string $className, protected array $constructorArguments = [], protected string|array $factory = '')
    {
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

    public function replaceArguments(array $arguments): void
    {
        $this->constructorArguments = $arguments;
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
        return ! empty($this->factory);
    }

    public function setFactory(string|array $factory): self
    {
        $this->factory = $factory;
        return $this;
    }

    public function addMethodCall(string $string, array $array): self
    {
        $this->methodCalls[] = new MethodCall($string, $array);

        return $this;
    }

    /**
     * @return MethodCall[]
     */
    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }
}