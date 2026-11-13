<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute;

use Attribute;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\QueryBus;

/**
 * licence Apache-2.0
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class ReferenceByQuery extends Reference
{
    public function __construct(string $routingKey)
    {
        parent::__construct(
            QueryBus::class,
            expression: static fn (#[Reference] QueryBus $queryBus): mixed => $queryBus->sendWithRouting($routingKey),
        );
    }
}
