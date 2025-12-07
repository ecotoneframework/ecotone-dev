<?php

declare(strict_types=1);

namespace Symfony\App\DbalConnectionRequirementWithConnection;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

/**
 * licence Apache-2.0
 */
class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../';
    }
}

