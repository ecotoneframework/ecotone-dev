<?php

namespace Monorepo\ExampleApp\Common\Domain\Article\Command;

readonly class ChangeTitle
{
    public function __construct(public string $id, public string $title)
    {
    }
}