<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\MultiTenant;

use Ecotone\Messaging\Support\LicensingException;
use Illuminate\Foundation\Http\Kernel;
use PHPUnit\Framework\TestCase;


final class MultiTenantLicensingTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('LARAVEL_LICENCE_KEY');
        restore_exception_handler();
        restore_error_handler();
    }

    public function test_throws_when_multi_tenant_configuration_is_used_without_enterprise_licence(): void
    {
        putenv('LARAVEL_LICENCE_KEY');
        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('Multi-tenancy');
        $this->expectExceptionMessage('https://docs.ecotone.tech/enterprise');

        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }
}
