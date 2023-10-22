<?php

namespace Ecotone\Messaging\Config\Container;

class Definition
{
    /**
     * @var MethodCall[]
     */
    private array $methodCalls = [];

    /**
     * @param array<string|int, mixed> $arguments
     */
    public function __construct(protected string $className, protected array $arguments = [], protected string|array $factory = '')
    {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getArguments(): array
    {
        return $this->arguments;
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