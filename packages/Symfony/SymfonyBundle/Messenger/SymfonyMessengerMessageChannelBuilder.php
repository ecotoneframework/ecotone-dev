<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageConverter\HeaderMapper;

/**
 * Symfony Channel does not implement MessageChannelWithSerializationBuilder to avoid
 * using PollableChannelSerializationModule, as serialization is done on the symfony transport layer instead.
 */
/**
 * licence Apache-2.0
 */
final class SymfonyMessengerMessageChannelBuilder implements MessageChannelBuilder
{
    private const TRANSPORT_SERVICE_PREFIX = 'messenger.transport.';

    private HeaderMapper $headerMapper;

    private string $acknowledgeMode = SymfonyAcknowledgementCallback::AUTO_ACK;

    private function __construct(private string $transportName)
    {
        $this->withHeaderMapping('*');
    }

    public static function create(string $transportName): self
    {
        return new self($transportName);
    }

    public function getMessageChannelName(): string
    {
        return $this->transportName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    /**
     * @param string $headerMapper
     * @return static
     */
    public function withHeaderMapping(string $headerMapper): self
    {
        $headerMapper = explode(',', $headerMapper);
        $this->headerMapper = DefaultHeaderMapper::createWith($headerMapper, $headerMapper);

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(
            SymfonyMessengerMessageChannel::class,
            [
                new Reference($this->getTransportServiceName()),
                new Definition(SymfonyMessageConverter::class, [
                    $this->headerMapper,
                    $this->acknowledgeMode,
                    new Reference(ConversionService::REFERENCE_NAME),
                ]),
            ]
        );
    }

    private function getTransportServiceName(): string
    {
        return self::TRANSPORT_SERVICE_PREFIX . $this->transportName;
    }
}
