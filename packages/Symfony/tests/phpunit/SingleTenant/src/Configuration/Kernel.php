<?php

namespace Symfony\App\SingleTenant\Configuration;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

/**
 * licence Apache-2.0
 */
class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../../';
    }
}
