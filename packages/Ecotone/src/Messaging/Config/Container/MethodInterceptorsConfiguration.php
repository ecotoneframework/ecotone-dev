<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;

/**
 * licence Apache-2.0
 */
class MethodInterceptorsConfiguration
{
    /**
     * @param array<MethodInterceptorBuilder> $beforeInterceptors
     * @param array<AroundInterceptorBuilder> $aroundInterceptors
     * @param array<MethodInterceptorBuilder> $afterInterceptors
     */
    public function __construct(
        private array $beforeInterceptors,
        private array $aroundInterceptors,
        private array $afterInterceptors,
    ) {
    }

    public static function createEmpty()
    {
        return new self([], [], []);
    }

    /**
     * @return MethodInterceptorBuilder[]
     */
    public function getBeforeInterceptors(): array
    {
        return $this->beforeInterceptors;
    }

    /**
     * @return AroundInterceptorBuilder[]
     */
    public function getAroundInterceptors(): array
    {
        return $this->aroundInterceptors;
    }

    /**
     * @return MethodInterceptorBuilder[]
     */
    public function getAfterInterceptors(): array
    {
        return $this->afterInterceptors;
    }
}
