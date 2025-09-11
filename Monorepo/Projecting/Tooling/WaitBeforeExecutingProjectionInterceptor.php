<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Monorepo\Projecting\Tooling;

use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Projecting\ProjectingManager;

class WaitBeforeExecutingProjectionInterceptor
{
    use WaitForUserInputTrait;
    #[Before(
        pointcut: ProjectingManager::class . '::execute'
    )]
    public function interceptor(): void
    {
        $this->waitForUserInput();
    }

    public static function getMessage(): string
    {
        return "Press enter to execute projection...\n";
    }
}