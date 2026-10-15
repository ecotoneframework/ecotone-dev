<?php

declare(strict_types=1);

namespace App\Workflow\Configuration;

use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function disableTransactions()
    {
        return DbalConfiguration::createWithDefaults()
            ->withTransactionOnCommandBus(false);
    }
}