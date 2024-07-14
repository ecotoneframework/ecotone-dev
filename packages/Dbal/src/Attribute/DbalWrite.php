<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Enqueue\Dbal\DbalConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class DbalWrite
{
    public function __construct(
        private string $sql,
        private string $connectionReferenceName = DbalConnectionFactory::class
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
