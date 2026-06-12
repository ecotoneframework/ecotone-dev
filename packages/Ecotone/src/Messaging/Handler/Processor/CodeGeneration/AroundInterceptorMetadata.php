<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\CodeGeneration;

/**
 * licence Apache-2.0
 */
final class AroundInterceptorMetadata
{
    public function __construct(
        public readonly string $methodName,
        public readonly bool $hasMethodInvocation,
    ) {
    }
}
