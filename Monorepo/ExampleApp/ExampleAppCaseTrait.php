<?php

namespace Monorepo\ExampleApp;

use Monorepo\ExampleApp\Symfony\Kernel;

trait ExampleAppCaseTrait
{
    protected static function getSymfonyKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function getProjectDir(): string
    {
        return __DIR__;
    }
}