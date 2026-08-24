<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\CustomRepositories\Standard;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class Page
{
    private function __construct(
        #[Identifier] private string $id
    ) {

    }

    #[CommandHandler('create.page')]
    public static function create(string $id): self
    {
        return new self($id);
    }
}
