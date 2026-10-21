<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Transport;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Scheduling\Duration;
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

    #[ServiceContext]
    public function flowTracking(): FlowTrackingConfiguration
    {
        return FlowTrackingConfiguration::createWithDefaults()
            ->withRetention(Duration::minutes(60))
            ->withTracingGateway(true);
    }

    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        $settings = $this->settings();

        $connections = $settings->transport === Transport::Dbal
            ? [DbalConnectionReference::DEFAULT, PulseSettings::TRANSPORT_CONNECTION_REFERENCE]
            : [DbalConnectionReference::DEFAULT];

        return DbalConfiguration::createWithDefaults()
            ->withDefaultConnectionReferenceNames($connections)
            ->withDeadLetter(false)
            ->withDocumentStore(false)
            ->withDeduplication(true, $settings->transport === Transport::Dbal ? PulseSettings::TRANSPORT_CONNECTION_REFERENCE : DbalConnectionReference::DEFAULT);
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
            static fn (string $name) => $name === $settings->channel ? self::ownChannel($settings, $name) : self::serviceChannel($settings, $name),
            array_values(array_unique($channelNames))
        );

        if ($settings->transport === Transport::Kafka) {
            $channels[] = KafkaBrokerConfiguration::createWithDefaults($settings->kafkaBootstrapServers());
        }

        return $channels;
    }

    private static function ownChannel(PulseSettings $settings, string $name): object
    {
        return match ($settings->transport) {
            Transport::Amqp => AmqpBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Redis => RedisBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Sqs => SqsBackedMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Kafka => KafkaMessageChannelBuilder::create($name)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Dbal => DbalBackedMessageChannelBuilder::create($name, PulseSettings::TRANSPORT_CONNECTION_REFERENCE)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
        };
    }

    private static function serviceChannel(PulseSettings $settings, string $name): object
    {
        return match ($settings->transport) {
            Transport::Amqp => AmqpBackedMessageChannelBuilder::create($name)->withRequiredExistingDestination(),
            Transport::Sqs => SqsBackedMessageChannelBuilder::create($name)->withRequiredExistingDestination(),
            Transport::Kafka => KafkaMessageChannelBuilder::create($name)->withRequiredExistingDestination(),
            Transport::Redis, Transport::Dbal => self::ownChannel($settings, $name),
        };
    }

    private function settings(): PulseSettings
    {
        return PulseSettings::fromEnvironment(PulseEnvironment::read(), dirname(__DIR__, 2));
    }
}
