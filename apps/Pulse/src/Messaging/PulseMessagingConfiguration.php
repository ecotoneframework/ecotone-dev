<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Transport;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;

final class PulseMessagingConfiguration
{
    private const RECEIVE_TIMEOUT_MILLISECONDS = 1000;

    #[ServiceContext]
    public function serviceConfiguration(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()
            ->withServiceName($this->settings()->serviceName)
            ->withDefaultSerializationMediaType('application/json');
    }

    #[ServiceContext]
    public function serviceMap(): DistributedServiceMap
    {
        $serviceMap = DistributedServiceMap::initialize();
        foreach ($this->settings()->serviceChannels as $service => $channel) {
            $serviceMap = $serviceMap->withCommandMapping(targetServiceName: $service, channelName: $channel);
        }

        return $serviceMap;
    }

    /**
     * @return array<object>
     */
    #[ServiceContext]
    public function channels(): array
    {
        $settings = $this->settings();
        $channelNames = [...array_values($settings->serviceChannels), $settings->channel];

        $channels = array_map(
            static fn (string $name) => match ($settings->transport) {
                Transport::Amqp => AmqpBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
                Transport::Redis => RedisBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
                Transport::Sqs => SqsBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
                Transport::Kafka => KafkaMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
                Transport::Dbal => DbalBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            },
            array_values(array_unique($channelNames))
        );

        if ($settings->transport === Transport::Kafka) {
            $channels[] = KafkaBrokerConfiguration::createWithDefaults($settings->kafkaBootstrapServers());
        }

        return $channels;
    }

    private function settings(): PulseSettings
    {
        return PulseSettings::fromEnvironment(PulseEnvironment::read(), dirname(__DIR__, 2));
    }
}
