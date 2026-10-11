<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Messaging\Message;

final class ErrorChannelService
{
    /** @var array<string, Message[]> */
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

    /** @return array<string, Message[]> */
    #[QueryHandler("getErrorMessages")]
    public function getErrorMessages(): array
    {
        return $this->errorMessages;
    }
}