<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Configuration;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

final class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../../';
    }
}
