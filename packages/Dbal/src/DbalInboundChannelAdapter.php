<?php

namespace Ecotone\Dbal;

use Doctrine\DBAL\Exception\ConnectionException;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Enqueue\Dbal\DbalContext;

class DbalInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        $context->createDataBaseTable();
    }

    public function connectionException(): string
    {
        return ConnectionException::class;
    }
}
