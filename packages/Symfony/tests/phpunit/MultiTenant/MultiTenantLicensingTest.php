<?php

declare(strict_types=1);

namespace Test\MultiTenant;

use Ecotone\Messaging\Support\LicensingException;
use PHPUnit\Framework\TestCase;
use Symfony\App\MultiTenant\Configuration\Kernel;

final class MultiTenantLicensingTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SYMFONY_LICENCE_KEY');
        restore_exception_handler();
    }

    public function test_throws_when_multi_tenant_configuration_is_used_without_enterprise_licence(): void
    {
        putenv('SYMFONY_LICENCE_KEY');

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Multi-tenancy');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        $kernel = new Kernel('missing_licence', true);
        $kernel->boot();
    }
}
