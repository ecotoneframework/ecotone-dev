<?php

namespace Fixture;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class TestBundle
 * @package Fixture
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
class TestBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
    }
}
