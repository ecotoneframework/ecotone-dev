<?php declare(strict_types=1);

namespace App\EventSourcing\Infrastructure;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

class EcotoneConfiguration
{
    #[ServiceContext]
    public function turnOffTransactions(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
                ->withTransactionOnCommandBus(false);
    }
}