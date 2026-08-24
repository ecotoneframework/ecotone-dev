<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Tooling;

use Ecotone\Api\Attribute\Interceptor\Before;
use Ecotone\Api\Gateway\ProjectingManager;

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
