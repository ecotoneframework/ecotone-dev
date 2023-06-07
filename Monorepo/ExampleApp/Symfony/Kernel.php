<?php

namespace Monorepo\ExampleApp\Symfony;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__;
    }
}