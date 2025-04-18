<?php

namespace Ecotone\SymfonyBundle\App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * licence Apache-2.0
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
