<?php

declare(strict_types=1);

namespace Ecotone\Api\ExtensionObject;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\TimeSpan;
use Ecotone\Messaging\Channel\Coroutine\CoroutineMessageChannel;
use Ecotone\Messaging\Channel\Coroutine\CoroutineMessageChannelTimer;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Support\MessageBuilder;
use Psr\Container\ContainerInterface;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class CoroutineMessageChannelBuilder implements MessageChannelBuilder, DelayedMessageChannelBuilder
{
    public const DEFAULT_COROUTINES = 10;

    private function __construct(public readonly string $messageChannelName, private readonly int $coroutines = self::DEFAULT_COROUTINES)
    {
    }

    public static function create(string $messageChannelName): self
    {
        return new self($messageChannelName);
    }

    public function withCoroutines(int $coroutines): self
    {
        if ($coroutines < 1) {
            throw ConfigurationException::create("Coroutine message channel `{$this->messageChannelName}` cannot run on {$coroutines} coroutines: it needs at least one. Pass a positive number to CoroutineMessageChannelBuilder::withCoroutines(), or leave it out for the default of " . self::DEFAULT_COROUTINES . '.');
        }
        return new self(...[...get_object_vars($this), 'coroutines' => $coroutines]);
    }

    public function getMessageChannelName(): string
    {
        return $this->messageChannelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function isStreamingChannel(): bool
    {
        return false;
    }

    public function supportsDelayedMessages(): bool
    {
        return true;
    }

    public function maximumDelay(): ?TimeSpan
    {
        return null;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $timer = new Definition(CoroutineMessageChannelTimer::class);
        foreach ($builder->getBootstrapConfiguration()->extensionObjects as $extension) {
            if ($extension instanceof TestConfiguration && $extension->timeAt !== null) {
                $timer = new Reference(EcotoneClockInterface::class);
            }
        }
        return new Definition(CoroutineMessageChannel::class, [$this->messageChannelName, new Reference(ContainerInterface::class), $this->coroutines, new Reference(EcotoneClockInterface::class), new Reference(MessageBuilder::class), $timer]);
    }
}
