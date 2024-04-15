<?php

declare(strict_types=1);

namespace App\Workflow\Configuration;

use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function disableTransactions()
    {
        return DbalConfiguration::createWithDefaults()
            ->withTransactionOnCommandBus(false);
    }
}