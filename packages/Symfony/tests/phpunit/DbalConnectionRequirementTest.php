<?php

declare(strict_types=1);

namespace Test;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\TestCase;
use Symfony\App\DbalConnectionRequirement\Kernel;

/**
 * Tests that Dbal module throws a user-friendly ConfigurationException when DbalConnectionFactory is not configured.
 *
 * @internal
 */
final class DbalConnectionRequirementTest extends TestCase
{
    public function test_real_symfony_application_throws_configuration_exception_when_dbal_connection_not_configured(): void
    {
        require_once __DIR__ . '/DbalConnectionRequirement/src/Kernel.php';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Dbal module requires 'Enqueue\Dbal\DbalConnectionFactory' to be configured");

        $kernel = new Kernel('test', true);
        $kernel->boot();
    }
}

