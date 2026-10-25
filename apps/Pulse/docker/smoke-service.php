<?php

declare(strict_types=1);

use App\Settings\Transport;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Amqp\AmqpLibConnectionFactory;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Api\Redis\RedisBackedMessageChannelBuilder;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Api\Sqs\SqsBackedMessageChannelBuilder;
use Ecotone\Api\Sqs\SqsConnectionFactory;

require __DIR__ . '/../vendor/autoload.php';

$dsn = getenv('PULSE_TRANSPORT_DSN');
$transport = Transport::fromDsn($dsn);
$service = getenv('SMOKE_SERVICE') ?: 'orders';
$pulse = 'ecotone_pulse';

$channel = static fn (string $name) => match ($transport) {
    Transport::Amqp => AmqpBackedMessageChannelBuilder::create($name)->withReceiveTimeout(1),
    Transport::Redis => RedisBackedMessageChannelBuilder::create($name)->withReceiveTimeout(1),
    Transport::Sqs => SqsBackedMessageChannelBuilder::create($name)->withReceiveTimeout(1),
    Transport::Kafka => KafkaMessageChannelBuilder::create($name)->withReceiveTimeout(1),
    Transport::Dbal => DbalBackedMessageChannelBuilder::create($name)->withReceiveTimeout(1),
};

$amqp = $transport === Transport::Amqp ? new AmqpExtConnectionFactory(['dsn' => $dsn]) : null;
$services = match ($transport) {
    Transport::Amqp => [AmqpExtConnectionFactory::class => $amqp, AmqpLibConnectionFactory::class => $amqp],
    Transport::Redis => [RedisConnectionFactory::class => new RedisConnectionFactory(['dsn' => $dsn, 'scheme_extensions' => ['phpredis']])],
    Transport::Sqs => [SqsConnectionFactory::class => new SqsConnectionFactory($dsn)],
    Transport::Kafka => [KafkaBrokerConfiguration::class => KafkaBrokerConfiguration::createWithDefaults(array_map('trim', explode(',', preg_replace('#^kafka://#i', '', $dsn))))],
    Transport::Dbal => [DbalConnectionFactory::class => new DbalConnectionFactory($dsn)],
};

$application = EcotoneLite::bootstrapFlowTesting(
    containerOrAvailableServices: $services,
    extensionObjects: [
        $channel($service),
        $channel($pulse),
        DistributedServiceMap::initialize()->withCommandMapping(targetServiceName: $pulse, channelName: $pulse),
        ServiceConfiguration::createWithDefaults()->withServiceName($service),
        PulseConfiguration::createWithDefaults($pulse),
    ],
    enabledModules: [ModulePackageList::CORE_PACKAGE, ...array_filter($transport->modulePackages(), static fn (string $package) => $package !== ModulePackageList::SYMFONY_PACKAGE)],
    licenceKey: getenv('ECOTONE_LICENCE_KEY'),
);

if ($transport === Transport::Kafka) {
    $configuration = new RdKafka\Conf();
    $configuration->set('metadata.broker.list', preg_replace('#^kafka://#i', '', $dsn));
    $producer = new RdKafka\Producer($configuration);
    $producer->getMetadata(false, $producer->newTopic($service), 10000);
}

$deadline = time() + (int) (getenv('SMOKE_SECONDS') ?: 180);
while (time() < $deadline) {
    $application->run($service, ExecutionPollingMetadata::createWithTestingSetup(executionTimeLimitInMilliseconds: $transport === Transport::Kafka ? 4000 : 1000));
    usleep(200_000);
}
