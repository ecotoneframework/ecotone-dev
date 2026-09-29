<?php

declare(strict_types=1);

namespace Symfony\App\DcbSmoke;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

/**
 * licence Enterprise
 */
class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return __DIR__ . '/../';
    }
}
