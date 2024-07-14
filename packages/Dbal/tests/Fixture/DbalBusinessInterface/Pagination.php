<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

/**
 * licence Apache-2.0
 */
final class Pagination
{
    public function __construct(public int $limit, public int $offset)
    {
    }
}
