<?php

declare(strict_types=1);

namespace App\Workflow\Configuration;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function disableTransactions()
    {
        return DbalConfiguration::createWithDefaults()
            ->withTransactionOnCommandBus(false);
    }
}