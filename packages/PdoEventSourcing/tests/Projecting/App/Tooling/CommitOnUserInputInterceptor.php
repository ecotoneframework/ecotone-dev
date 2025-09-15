<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Tooling;

use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Transaction\Transactional;
use Ecotone\Modelling\CommandBus;

class CommitOnUserInputInterceptor
{
    use WaitForUserInputTrait;

    #[Around(
        precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1,
        pointcut: CommandBus::class . '||' . Transactional::class
    )]
    public function interceptor(MethodInvocation $methodInvocation): mixed
    {
        $response = $methodInvocation->proceed();
        $this->waitForUserInput();
        return $response;
    }

    public static function getMessage(): string
    {
        return "Press enter to commit transaction...\n";
    }
}
