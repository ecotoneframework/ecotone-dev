<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

interface MethodInvocation
{
    /**
     * Proceed with invocation. Returns value of invoked method
     */
    public function proceed(): mixed;

    public function cloneCurrentState(): self;

    public function getObjectToInvokeOn(): string|object;

    public function getMethodName(): string;


    public function getName(): string;

    /**
     * @return mixed[]
     */
    public function getArguments(): array;

}
