<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class DbalWrite
{
    public function __construct(
        private string $sql,
        private string $connectionReferenceName = DbalConnectionReference::DEFAULT
    ) {

    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }
}
