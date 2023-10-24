<?php

namespace Monorepo\ExampleAppEventSourcing;


use Monorepo\ExampleAppEventSourcing\Symfony\Kernel;

trait ExampleAppEventSourcingCaseTrait
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