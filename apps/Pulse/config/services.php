<?php

declare(strict_types=1);

use App\Settings\PulseEnvironment;
use App\Settings\PulseSettings;
use App\Settings\Transport;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Redis\RedisConnectionFactory;
use Ecotone\Api\Sqs\SqsConnectionFactory;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $settings = PulseSettings::fromEnvironment(PulseEnvironment::read(), dirname(__DIR__));

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
        '$cacheDir' => $settings->cacheDir,
    ]);

    $services->set('pulse.cache', FilesystemAdapter::class)->args(['pulse', 0, $settings->cacheDir]);

    $connectionFactory = match ($settings->transport) {
        Transport::Amqp => AmqpExtConnectionFactory::class,
        Transport::Redis => RedisConnectionFactory::class,
        Transport::Sqs => SqsConnectionFactory::class,
        Transport::Dbal => DbalConnectionFactory::class,
        Transport::Kafka => null,
    };
    if ($connectionFactory !== null) {
        $services->set($connectionFactory)->args(['%env(PULSE_TRANSPORT_DSN)%']);
    }
};
