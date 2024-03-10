<?php

namespace Monorepo\ExampleApp\Common\Domain\Article\Command;

class ChangeContent
{
    public function __construct(public string $id, public string $content)
    {
    }
}