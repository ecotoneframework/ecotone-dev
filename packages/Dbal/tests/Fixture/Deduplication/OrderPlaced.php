<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

final class OrderPlaced
{
    public function __construct(public string $order)
    {
    }
}
