<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test;

final class TestConfiguration
{
    private function __construct(
        private bool $failOnCommandHandlerNotFound,
        private bool $failOnQueryHandlerNotFound
    ) {

    }

    public static function createWithDefaults(): self
    {
        return new self(true, true);
    }

    public function withFailOnCommandHandlerNotFound(bool $shouldFail): self
    {
        $self = clone $this;
        $self->failOnCommandHandlerNotFound = $shouldFail;

        return $self;
    }

    public function withFailOnQueryHandlerNotFound(bool $shouldFail): self
    {
        $self = clone $this;
        $self->failOnQueryHandlerNotFound = $shouldFail;

        return $self;
    }

    public function isFailingOnCommandHandlerNotFound(): bool
    {
        return $this->failOnCommandHandlerNotFound;
    }

    public function isFailingOnQueryHandlerNotFound(): bool
    {
        return $this->failOnQueryHandlerNotFound;
    }
}