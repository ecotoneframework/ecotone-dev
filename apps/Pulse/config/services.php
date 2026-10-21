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

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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
        '$cacheDir' => $settings->cacheDir,
    ]);

    $services->alias('pulse.clock', 'clock');
    $services->set('pulse.cache', FilesystemAdapter::class)->args(['pulse', 0, $settings->cacheDir]);

    $services->set('pulse.lock_store', FlockStore::class)->args([$settings->cacheDir . '/locks']);
    $services->set('pulse.lock_factory', LockFactory::class)->args([service('pulse.lock_store')]);

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
