<?php

namespace Ecotone\Modelling;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;

interface RepositoryBuilder extends CompilableBuilder
{
    public function canHandle(string $aggregateClassName): bool;

    public function isEventSourced(): bool;

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): EventSourcedRepository|StandardRepository;
}
