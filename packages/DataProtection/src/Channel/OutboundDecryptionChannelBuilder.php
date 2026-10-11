<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */

namespace Ecotone\DataProtection\Channel;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\PrecedenceChannelInterceptor;

readonly class OutboundDecryptionChannelBuilder implements ChannelInterceptorBuilder
{
    public function __construct(
        private string $relatedChannel,
        private Reference $encryptionKey,
        private bool $isPayloadSensitive,
        private array $sensitiveHeaders,
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
                $this->encryptionKey,
                $this->isPayloadSensitive,
                $this->sensitiveHeaders,
            ]
        );
    }
}
