<?php

declare(strict_types=1);

namespace Fixture\User;

use Ecotone\Api\ServiceContext;
use Ecotone\Lite\Test\Configuration\InMemoryRepositoryBuilder;

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
