<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ClosureInAttribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
final class DedupPolicy
{
    public function __construct(public string $scope)
    {
    }
}
