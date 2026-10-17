<?php

declare(strict_types=1);

namespace Ecotone\Api\Kafka;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
interface KafkaHeader
{
    public const ACKNOWLEDGE_HEADER_NAME = 'ecotone_kafka_consumer_acknowledge';
    public const TOPIC_HEADER_NAME = 'kafka_topic';
    public const PARTITION_HEADER_NAME = 'kafka_partition';
    public const OFFSET_HEADER_NAME = 'kafka_offset';
    public const KAFKA_TIMESTAMP_HEADER_NAME = 'kafka_timestamp';
    public const KAFKA_TARGET_PARTITION_KEY_HEADER_NAME = 'ecotone.kafka.partition_target_key';
    public const KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME = 'ecotone.kafka.partition_source_key';
}
