<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;

class MethodInterceptorsConfiguration
{
    /**
     * @param array<MethodInterceptor> $beforeInterceptors
     * @param array<AroundInterceptorBuilder> $aroundInterceptors
     * @param array<MethodInterceptor> $afterInterceptors
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

    public function getRelatedInterceptors(InterfaceToCall $interceptedInterfaceToCall, ): array
    {
        return array_merge(
            $this->beforeInterceptors[$interceptorName] ?? [],
            $this->aroundInterceptors[$interceptorName] ?? [],
            $this->afterInterceptors[$interceptorName] ?? []
        );
    }
}