<?php

namespace Monorepo\ExampleApp\Common\Infrastructure;

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
}