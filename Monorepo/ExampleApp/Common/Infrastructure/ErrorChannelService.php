<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Ecotone\Api\InternalHandler;
use Ecotone\Messaging\Support\ErrorMessage;
use Ecotone\Api\QueryHandler;
use Ecotone\Messaging\Message;

final class ErrorChannelService
{
    /** @var ErrorMessage[] */
    private array $errorMessages = [
        "defaultDeadLetter" => [],
        "customDeadLetter" => []
    ];

    #[InternalHandler("default_dead_letter")]
    public function errorChannel(Message $errorMessage): void
    {
        $this->errorMessages["defaultDeadLetter"][] = $errorMessage;
    }

    #[InternalHandler("custom_dead_letter")]
    public function customErrorChannel(Message $errorMessage): void
    {
        $this->errorMessages["customDeadLetter"][] = $errorMessage;
    }

    /** @return Message[] */
    #[QueryHandler("getErrorMessages")]
    public function getErrorMessages(): array
    {
        return $this->errorMessages;
    }
}