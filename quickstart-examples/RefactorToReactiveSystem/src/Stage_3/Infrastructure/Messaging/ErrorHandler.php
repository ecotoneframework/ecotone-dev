<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Infrastructure\Messaging;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Messaging\Support\ErrorMessage;

final class ErrorHandler
{
    #[InternalHandler("finalErrorChannel")]
    public function handle(ErrorMessage $message): void
    {
        echo "Message failed";
    }
}