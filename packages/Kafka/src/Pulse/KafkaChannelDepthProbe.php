<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Pulse;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelDepth;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelProbe;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;
use Throwable;

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
 *
 * @internal
 */
final class KafkaChannelDepthProbe implements PulseChannelProbe
{
    public function __construct(private KafkaAdmin $kafkaAdmin)
    {
    }

    public function depthsOf(array $destinationByChannel, int $budgetInMilliseconds): array
    {
        $depths = [];
        foreach ($destinationByChannel as $channel => $channelName) {
            $depths[$channel] = $this->lagOf($channelName, $budgetInMilliseconds);
        }

        return $depths;
    }

    private function lagOf(string $channelName, int $timeoutInMilliseconds): PulseChannelDepth
    {
        ['consumer' => $consumer, 'topics' => $topics] = $this->kafkaAdmin->createGroupInspector($channelName);
        try {
            $lag = 0;
            foreach ($topics as $topic) {
                $lag += $this->lagOfTopic($consumer, $topic, $timeoutInMilliseconds);
            }

            return PulseChannelDepth::measured($lag);
        } catch (Throwable $failure) {
            return PulseChannelDepth::failed("Kafka lag of channel '{$channelName}' could not be measured: {$failure->getMessage()}");
        } finally {
            $consumer->close();
        }
    }

    private function lagOfTopic(KafkaConsumer $consumer, string $topic, int $timeoutInMilliseconds): int
    {
        $topicMetadata = null;
        foreach ($consumer->getMetadata(false, $consumer->newTopic($topic), $timeoutInMilliseconds)->getTopics() as $candidate) {
            $topicMetadata = $candidate;
        }
        if ($topicMetadata === null || $topicMetadata->getErr() !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new \RuntimeException("Kafka topic '{$topic}' does not exist yet. It is created when the first message is sent to the channel, or create it on the broker");
        }

        $lag = 0;
        foreach ($topicMetadata->getPartitions() as $partition) {
            $low = 0;
            $high = 0;
            $consumer->queryWatermarkOffsets($topic, $partition->getId(), $low, $high, $timeoutInMilliseconds);
            $committed = $consumer->getCommittedOffsets([new TopicPartition($topic, $partition->getId())], $timeoutInMilliseconds)[0]->getOffset();
            $lag += max(0, $high - ($committed >= 0 ? $committed : $low));
        }

        return $lag;
    }
}
