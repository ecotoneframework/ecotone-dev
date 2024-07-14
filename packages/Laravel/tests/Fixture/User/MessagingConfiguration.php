<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\User;

use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

/**
 * licence Apache-2.0
 */
final class MessagingConfiguration
{
    #[ServiceContext]
    public function withInMemoryChannel(): InMemoryRepositoryBuilder
    {
        return InMemoryRepositoryBuilder::createForAllStateStoredAggregates();
    }
}
