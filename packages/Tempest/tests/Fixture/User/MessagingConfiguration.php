<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\User;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;

/**
 * licence Apache-2.0
 */
final class MessagingConfiguration
{
    #[ServiceContext]
    public function withInMemoryRepository(): InMemoryRepositoryBuilder
    {
        return InMemoryRepositoryBuilder::createForAllStateStoredAggregates();
    }
}
