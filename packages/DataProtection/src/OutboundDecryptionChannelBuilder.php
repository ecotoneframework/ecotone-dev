<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\PrecedenceChannelInterceptor;

readonly class OutboundDecryptionChannelBuilder implements ChannelInterceptorBuilder
{
    public function __construct(
        private string $relatedChannel,
        private ?Reference $channelObfuscatorReference,
        private array $messageObfuscatorReferences,
    ) {
    }

    public function relatedChannelName(): string
    {
        return $this->relatedChannel;
    }

    public function getPrecedence(): int
    {
        return PrecedenceChannelInterceptor::MESSAGE_SERIALIZATION + 1;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(
            OutboundDecryptionChannelInterceptor::class,
            [
                $this->channelObfuscatorReference,
                $this->messageObfuscatorReferences,
            ]
        );
    }
}
