<?php

namespace Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers;

class ProductBecameBillable
{
    public function __construct(
        public string $productId,
        public string $debtor,
    ) {
    }
}
