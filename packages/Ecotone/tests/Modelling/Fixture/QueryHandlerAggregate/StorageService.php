<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\QueryHandlerAggregate;

use Ecotone\Api\BusinessMethod;
use Ecotone\Api\Identifier;
use Ecotone\Messaging\Message;

interface StorageService
{
    #[BusinessMethod('storage.getSmallBoxes')]
    public function getSmallBoxes(#[Identifier] $storageId): Message;

    #[BusinessMethod('storage.getBoxes')]
    public function getBoxes(#[Identifier] $storageId): Message;
}
