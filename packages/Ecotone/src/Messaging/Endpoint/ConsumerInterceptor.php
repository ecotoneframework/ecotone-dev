<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Throwable;

/**
 * Interface ConsumerExtension
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
interface ConsumerInterceptor
{
    /**
     * Do some one time action before this consumer will start running
     */
    public function onStartup(): void;

    /**
     * should this consumer be stopped before next run
     */
    public function shouldBeStopped(): bool;

    /**
     * handle exception
     */
    public function shouldBeThrown(Throwable $exception): bool;

    /**
     *  Called before each run
     */
    public function preRun(): void;

    /**
     * Called after each run
     */
    public function postRun(): void;

    /**
     * Called after each sending message to request channel
     *
     * @return mixed result of method invocation
     */
    public function postSend(MethodInvocation $methodInvocation): mixed;

    public function isInterestedInPostSend(): bool;
}
