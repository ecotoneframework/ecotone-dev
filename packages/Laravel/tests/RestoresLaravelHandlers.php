<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use PHPUnit\Framework\Attributes\After;

trait RestoresLaravelHandlers
{
    #[After]
    public function restoreLaravelHandlers(): void
    {
        HandleExceptions::flushState($this);
    }
}
