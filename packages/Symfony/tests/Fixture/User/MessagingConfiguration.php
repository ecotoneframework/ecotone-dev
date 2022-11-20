<?php

declare(strict_types=1);

namespace Fixture\User;

use Ecotone\Lite\Test\Configuration\InMemoryStateStoredRepositoryBuilder;
use Ecotone\Messaging\Attribute\ServiceContext;

final class MessagingConfiguration
{
    #[ServiceContext]
    public function withInMemoryChannel(): InMemoryStateStoredRepositoryBuilder
    {
        return InMemoryStateStoredRepositoryBuilder::createForAllAggregates();
    }
}
