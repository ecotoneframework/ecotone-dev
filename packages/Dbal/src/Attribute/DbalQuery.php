<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Attribute;

use Attribute;
use Ecotone\Dbal\DbaBusinessMethod\FetchMode;
use Enqueue\Dbal\DbalConnectionFactory;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * licence Apache-2.0
 */
class DbalQuery
{
    public function __construct(
        private string  $sql,
        private int     $fetchMode = FetchMode::ASSOCIATIVE,
        private ?string $replyContentType = null,
        private string  $connectionReferenceName = DbalConnectionFactory::class
    ) {

    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getFetchMode(): int
    {
        return $this->fetchMode;
    }

    public function getReplyContentType(): ?string
    {
        return $this->replyContentType;
    }

    public function getConnectionReferenceName(): string
    {
        return $this->connectionReferenceName;
    }
}
