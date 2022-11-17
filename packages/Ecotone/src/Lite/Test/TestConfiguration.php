<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test;

use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Support\Assert;

final class TestConfiguration
{
    private function __construct(
        private bool $failOnCommandHandlerNotFound,
        private bool $failOnQueryHandlerNotFound,
        private ?MediaType $pollableChannelMediaTypeConversion,
        private string $channelToConvertOn
    ) {

    }

    public static function createWithDefaults(): self
    {
        return new self(true, true, null, "");
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

    public function withMediaTypeConversion(string $channelName, MediaType $mediaType): self
    {
        Assert::notNullAndEmpty($channelName, "Converted channel can not be empty");

        $self = clone $this;
        $self->pollableChannelMediaTypeConversion = $mediaType;
        $self->channelToConvertOn = $channelName;

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

    public function getPollableChannelMediaTypeConversion(): ?MediaType
    {
        return $this->pollableChannelMediaTypeConversion;
    }

    public function getChannelToConvertOn(): string
    {
        return $this->channelToConvertOn;
    }
}