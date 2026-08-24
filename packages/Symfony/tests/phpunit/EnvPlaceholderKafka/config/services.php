<?php

use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ModulePackageList;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('ecotone', [
        'modulePackages' => [ModulePackageList::SYMFONY_PACKAGE,
            ModulePackageList::KAFKA_PACKAGE,
            ModulePackageList::DBAL_PACKAGE, ],
        'licenceKey' => '%env(SYMFONY_LICENCE_KEY)%',
    ]);

    $services = $containerConfigurator->services();

    $services->set(KafkaBrokerConfiguration::class)
        ->factory([KafkaBrokerConfiguration::class, 'createWithDefaults'])
        ->args([['%env(KAFKA_DSN)%']])
        ->public();

    $services->set(DbalConnectionFactory::class)
        ->args(['%env(DATABASE_DSN)%'])
        ->public();

    $services->load('Symfony\\App\\EnvPlaceholderKafka\\', '%kernel.project_dir%/src/')
        ->autowire()
        ->autoconfigure();
};
