<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Ecotone\Messaging\Attribute\BusinessMethod;
use Enqueue\Dbal\DbalConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
class DbalWriteBusinessMethod
{
    public function __construct(
        private string $sql,
        private string $connectionReferenceName = DbalConnectionFactory::class
    )
    {

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