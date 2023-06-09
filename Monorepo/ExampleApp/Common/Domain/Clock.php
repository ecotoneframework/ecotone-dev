<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain;

interface Clock
{
    public function getCurrentTime(): \DateTimeImmutable;
}