<?php

namespace Test\Ecotone\Messaging\Fixture\FetchAggregate;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
class PlaceOrder
{
    public function __construct(
        private string $orderId,
        private ?string $userId,
        private string $productName
    ) {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }
}
