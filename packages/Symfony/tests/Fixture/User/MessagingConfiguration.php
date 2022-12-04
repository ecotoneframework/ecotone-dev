<?php

declare(strict_types=1);

namespace Fixture\User;

use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

final class MessagingConfiguration
{
    #[ServiceContext]
    public function withInMemoryChannel(): InMemoryRepositoryBuilder
    {
        return InMemoryRepositoryBuilder::createForAllStateStoredAggregates();
    }
}
