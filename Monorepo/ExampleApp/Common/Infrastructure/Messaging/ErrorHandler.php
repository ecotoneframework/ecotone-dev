<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure\Messaging;

use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Support\ErrorMessage;

final class ErrorHandler
{
    #[ServiceActivator("finalErrorChannel")]
    public function handle(ErrorMessage $message): void
    {
        echo "Message failed";
    }
}