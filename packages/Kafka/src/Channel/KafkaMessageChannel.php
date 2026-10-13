<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Channel;

use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\PollableChannel;
use Ecotone\Kafka\Configuration\KafkaConsumerConfiguration;
use Ecotone\Kafka\Inbound\KafkaInboundChannelAdapter;
use Ecotone\Kafka\Outbound\KafkaOutboundChannelAdapter;
use Ecotone\Messaging\Channel\BatchSupportingMessageChannel;

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class KafkaMessageChannel implements PollableChannel, BatchSupportingMessageChannel
{
    public function __construct(
        private KafkaInboundChannelAdapter $inboundChannelAdapter,
        private KafkaOutboundChannelAdapter $outboundChannelAdapter,
    ) {

    }

    public function supportsBatchMessages(): bool
    {
        return $this->outboundChannelAdapter->isBatchPublishingEnabled();
    }

    public function send(Message $message): void
    {
        $this->outboundChannelAdapter->handle($message);
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout($pollingMetadata);
    }

    public function onConsumerStop(): void
    {
        $this->inboundChannelAdapter->onConsumerStop();
    }

    public function receive(): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout(PollingMetadata::create(
            $this->inboundChannelAdapter->channelName
        )->withFixedRateInMilliseconds(KafkaConsumerConfiguration::DEFAULT_RECEIVE_TIMEOUT));
    }

    public function __toString(): string
    {
        return (string) $this->outboundChannelAdapter;
    }
}
