<?php

namespace Test\Ecotone\Modelling\Fixture\MultipleMessageHandlers;

class ProductDebtorWasChanged
{
    public function __construct(
        public string $productId,
        public string $oldDebtor,
        public string $newDebtor,
    ) {
    }
}
