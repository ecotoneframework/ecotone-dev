<?php

declare(strict_types=1);

use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Transport;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $settings = PulseSettings::fromEnvironment(PulseEnvironment::read(), dirname(__DIR__));

    $container->parameters()->set('pulse.graph_default_node_limit', 150);

    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->load('App\\', '../src/')->exclude('../src/{Kernel.php,Settings,Messaging}');

    $services->set(PulseSettings::class)->args([
        '$serviceName' => $settings->serviceName,
        '$channel' => $settings->channel,
        '$transportDsn' => '%env(PULSE_TRANSPORT_DSN)%',
        '$transport' => $settings->transport,
        '$serviceChannels' => $settings->serviceChannels,
        '$replyTimeoutSeconds' => $settings->replyTimeoutSeconds,
        '$renderWaitSeconds' => $settings->renderWaitSeconds,
        '$refreshIntervalSeconds' => $settings->refreshIntervalSeconds,
        '$internalDatabasePath' => $settings->internalDatabasePath,
    ]);

    $services->alias('pulse.clock', 'clock');
    $services->set(DbalConnectionFactory::class)->args([$settings->internalDatabaseDsn()]);

    $connectionFactory = match ($settings->transport) {
        Transport::Amqp => AmqpExtConnectionFactory::class,
        Transport::Redis => RedisConnectionFactory::class,
        Transport::Sqs => SqsConnectionFactory::class,
        Transport::Dbal => PulseSettings::TRANSPORT_CONNECTION_REFERENCE,
        Transport::Kafka => null,
    };
    if ($connectionFactory !== null) {
        $services->set($connectionFactory, $settings->transport === Transport::Dbal ? DbalConnectionFactory::class : null)->args(['%env(PULSE_TRANSPORT_DSN)%']);
    }
};
