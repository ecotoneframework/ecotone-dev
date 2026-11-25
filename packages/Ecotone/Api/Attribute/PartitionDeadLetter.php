<?php

declare(strict_types=1);

namespace Ecotone\Api\Attribute;

use Attribute;
use Closure;
use Ecotone\Messaging\Attribute\WithExpression;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class PartitionDeadLetter implements WithExpression
{
    public function __construct(
        public readonly string $partitionHeaderName = '',
        private string|Closure|null $expression = null,
        public readonly ?string $trackingName = null,
    ) {
    }

    public function getExpression(): string|Closure|null
    {
        return $this->expression;
    }
}
