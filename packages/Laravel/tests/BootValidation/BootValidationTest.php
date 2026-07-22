<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\BootValidation;

use App\BootValidation\Laravel\FactoryRegistrationProvider;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Messaging\Config\ConfigurationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Boot-time validation of external references in the Laravel integration:
 * services referenced by the compiled messaging system must be checked at
 * boot as a capability probe (bound() / constructor reflection) — never by
 * resolving them — so a missing reference fails the application boot honestly
 * instead of surfacing later as a misleading runtime wiring error.
 *
 * licence Apache-2.0
 * @internal
 */
final class BootValidationTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEcotoneCache();
        FactoryRegistrationProvider::$factoryInvoked = false;
    }

    protected function tearDown(): void
    {
        putenv('ECOTONE_BOOT_NS');
        putenv('ECOTONE_BOOT_REGISTER_FACTORY');

        $this->clearEcotoneCache();
    }

    public function test_missing_handler_dependency_fails_boot_with_honest_error(): void
    {
        putenv('ECOTONE_BOOT_NS=App\BootValidation\Laravel\Shared');

        try {
            $this->bootApplication();

            $this->fail('Boot must fail: ReportGenerator references MissingServiceContract which nothing provides');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(
                'MissingServiceContract',
                $exception->getMessage(),
                'The boot error must name the unresolvable reference',
            );
        }
    }

    public function test_dependency_registered_through_factory_binding_passes_boot_without_invoking_the_factory(): void
    {
        putenv('ECOTONE_BOOT_NS=App\BootValidation\Laravel\Shared');
        putenv('ECOTONE_BOOT_REGISTER_FACTORY=1');

        $this->bootApplication();

        $this->assertFalse(
            FactoryRegistrationProvider::$factoryInvoked,
            'Boot validation must accept a registered factory binding as proof of availability without invoking it',
        );
    }

    public function test_dependency_whose_own_dependency_does_not_exist_fails_boot(): void
    {
        putenv('ECOTONE_BOOT_NS=App\BootValidation\Laravel\Chain');

        try {
            $this->bootApplication();

            $this->fail('Boot must fail: RequiresMissingClass needs NonExistingCollaborator in its constructor');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(
                'RequiresMissingClass',
                $exception->getMessage(),
                'The boot error must name the transitively unresolvable reference',
            );
        }
    }

    public function test_failing_boot_is_idempotent_and_reports_the_same_error_on_rerun(): void
    {
        putenv('ECOTONE_BOOT_NS=App\BootValidation\Laravel\Shared');

        $firstException = $this->bootAndCaptureFailure();
        $secondException = $this->bootAndCaptureFailure();

        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed boot must not leave state behind that changes the error on the next attempt',
        );
    }

    private function bootAndCaptureFailure(): Throwable
    {
        try {
            $this->bootApplication();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Boot was expected to fail');
    }

    private function bootApplication(): Application
    {
        $app = require __DIR__ . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function clearEcotoneCache(): void
    {
        EcotoneCacheClear::clearEcotoneCacheDirectories(
            __DIR__ . '/storage/framework/cache/data',
        );
    }
}
