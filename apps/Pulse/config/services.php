<?php

declare(strict_types=1);

use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Transport;
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
    $services->set(DbalConnectionFactory::class)->args([$settings->internalDatabaseConfiguration()]);

    foreach ($settings->connections as $connection) {
        $dsn = '%env(' . $connection->environmentVariable . ')%';
        match ($connection->transport) {
            Transport::Amqp => $services->set($connection->referenceName(), AmqpExtConnectionFactory::class)->args([$dsn]),
            Transport::Redis => $services->set($connection->referenceName(), RedisConnectionFactory::class)->args([['dsn' => $dsn, 'scheme_extensions' => ['phpredis']]]),
            Transport::Sqs => $services->set($connection->referenceName(), SqsConnectionFactory::class)->args([$dsn]),
            Transport::Dbal => $services->set($connection->referenceName(), DbalConnectionFactory::class)->args([$dsn]),
            Transport::Kafka => $services->set(KafkaBrokerConfiguration::class)->args([$connection->kafkaBootstrapServers()]),
        };
    }
};
