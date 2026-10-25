<?php

declare(strict_types=1);

use App\Settings\Connection;
use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Transport;
use App\Storage\InternalDatabaseConnectionFactory;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $settings = PulseSettings::fromEnvironment(PulseEnvironment::read(), dirname(__DIR__));

    $container->parameters()->set('pulse.graph_default_node_limit', 150);

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->load('App\\', '../src/')->exclude('../src/{Kernel.php,Settings,Messaging}');

    $services->set(PulseSettings::class)->factory([PulseSettings::class, 'fromProcessEnvironment'])->args([dirname(__DIR__)]);

    $services->alias('pulse.clock', 'clock');
    $services->set(DbalConnectionFactory::class, InternalDatabaseConnectionFactory::class)->args([$settings->internalDatabasePath, PulseSettings::INTERNAL_DATABASE_BUSY_TIMEOUT_SECONDS]);

    foreach ($settings->connections as $connection) {
        $dsn = '%env(' . $connection->environmentVariable . ')%';
        match ($connection->transport) {
            Transport::Amqp => $services->set($connection->referenceName(), AmqpExtConnectionFactory::class)->args([$dsn])->public(),
            Transport::Redis => $services->set($connection->referenceName(), RedisConnectionFactory::class)->args([['dsn' => $dsn, 'scheme_extensions' => ['phpredis']]])->public(),
            Transport::Sqs => $services->set($connection->referenceName(), SqsConnectionFactory::class)->args([$dsn])->public(),
            Transport::Dbal => $services->set($connection->referenceName(), DbalConnectionFactory::class)->args([$dsn])->public(),
            Transport::Kafka => $services->set(KafkaBrokerConfiguration::class)->args([$connection->kafkaBootstrapServers()]),
        };
    }

    $brokers = array_values(array_filter($settings->connections, static fn (Connection $connection) => $connection->transport === Transport::Amqp));
    if ($brokers !== [] && $settings->defaultConnection()->transport !== Transport::Amqp) {
        $services->alias(AmqpExtConnectionFactory::class, $brokers[0]->referenceName());
    }
};
