<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterface;

final class Pagination
{
    public function __construct(public int $limit, public int $offset)
    {
    }
}
