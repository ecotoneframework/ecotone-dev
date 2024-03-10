<?php

namespace Monorepo\ExampleApp\Common\Infrastructure;

use Ecotone\Modelling\Attribute\QueryHandler;

class Output
{
    private array $messages = [];

    public function write(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array<string>
     */
    public function read(): array
    {
        return $this->messages;
    }

    #[QueryHandler("getMessages")]
    public function getMessages(): array
    {
        return $this->messages;
    }
}