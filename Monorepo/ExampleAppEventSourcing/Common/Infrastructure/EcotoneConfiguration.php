<?php declare(strict_types=1);

namespace Monorepo\ExampleAppEventSourcing\Common\Infrastructure;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function turnOffTransactions(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function serviceConfiguration(): ServiceConfiguration
    {
        return ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('errorChannel');
    }

    #[ServiceContext]
    public function es(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createWithDefaults();
    }
}