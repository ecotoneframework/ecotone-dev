<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Settings\Connection;
use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\ServiceRoute;
use App\Settings\Transport;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
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

        $databases = array_values(array_filter(array_map(
            static fn (Connection $connection) => $connection->transport === Transport::Dbal ? $connection->referenceName() : null,
            $settings->connections
        )));

        return DbalConfiguration::createWithDefaults()
            ->withDefaultConnectionReferenceNames([DbalConnectionReference::DEFAULT, ...$databases])
            ->withDeadLetter(false)
            ->withDocumentStore(false)
            ->withDeduplication(true, $settings->transport() === Transport::Dbal ? PulseSettings::TRANSPORT_CONNECTION_REFERENCE : DbalConnectionReference::DEFAULT);
    }

    /**
     * @return array<object>
     */
    #[ServiceContext]
    public function channels(): array
    {
        $settings = $this->settings();

        $channels = [];
        foreach ($settings->connections as $connection) {
            $channels[] = self::replyChannel($connection, PulseSettings::replyChannelName($connection, $settings->channel));
        }
        foreach ($settings->routes as $route) {
            $channels[] = self::requestChannel($settings->connections[$route->connection], $route);
        }

        return $channels;
    }

    private static function replyChannel(Connection $connection, string $channelName): object
    {
        $reference = $connection->referenceName();

        return match ($connection->transport) {
            Transport::Amqp => AmqpBackedMessageChannelBuilder::create($channelName, $reference, $connection->replyDestination)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Redis => RedisBackedMessageChannelBuilder::create($channelName, $reference)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Sqs => SqsBackedMessageChannelBuilder::create($channelName, $reference)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Kafka => KafkaMessageChannelBuilder::create($channelName, $connection->replyDestination)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Dbal => DbalBackedMessageChannelBuilder::create($channelName, $reference)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
        };
    }

    private static function requestChannel(Connection $connection, ServiceRoute $route): object
    {
        $reference = $connection->referenceName();

        return match ($connection->transport) {
            Transport::Amqp => AmqpBackedMessageChannelBuilder::create($route->channel, $reference, $route->destination)->withRequiredExistingDestination(),
            Transport::Sqs => SqsBackedMessageChannelBuilder::create($route->channel, $reference)->withRequiredExistingDestination(),
            Transport::Kafka => KafkaMessageChannelBuilder::create($route->channel, $route->destination)->withRequiredExistingDestination(),
            Transport::Redis => RedisBackedMessageChannelBuilder::create($route->channel, $reference)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
            Transport::Dbal => DbalBackedMessageChannelBuilder::create($route->channel, $reference)->withReceiveTimeout(self::RECEIVE_TIMEOUT_MILLISECONDS),
        };
    }

    private function settings(): PulseSettings
    {
        return PulseSettings::fromEnvironment(PulseEnvironment::read(), dirname(__DIR__, 2));
    }
}
