<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\MissingReference;

use App\MissingReference\Laravel\FactoryRegistrationProvider;
use Ecotone\Laravel\EcotoneCacheClear;
use Ecotone\Modelling\CommandBus;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Runtime failures for services the compiled messaging system references from
 * Laravel's container. Boot stays lazy — a handler with a missing dependency
 * must NOT fail the application boot; dispatching must fail with an error
 * naming the missing service, and dispatching again must report the very same
 * error instead of a misleading follow-up ("no message handler registered")
 * caused by half-built services left behind by the first failure.
 *
 * licence Apache-2.0
 * @internal
 */
final class MissingReferenceRuntimeFailureTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEcotoneCache();
        FactoryRegistrationProvider::$factoryInvoked = false;
    }

    protected function tearDown(): void
    {
        restore_exception_handler();
        restore_error_handler();

        putenv('ECOTONE_MISSING_REF_NS');
        putenv('ECOTONE_MISSING_REF_REGISTER_FACTORY');

        $this->clearEcotoneCache();
    }

    public function test_missing_handler_dependency_boots_but_fails_at_dispatch_with_honest_error(): void
    {
        putenv('ECOTONE_MISSING_REF_NS=App\MissingReference\Laravel\Shared');

        $app = $this->bootApplication();
        $commandBus = $app->make(CommandBus::class);

        $firstException = $this->dispatchAndCaptureFailure($commandBus, 'missing_reference.generate');
        $this->assertStringContainsString(
            'MissingServiceContract',
            $firstException->getMessage(),
            'The dispatch error must name the unresolvable reference',
        );

        $secondException = $this->dispatchAndCaptureFailure($commandBus, 'missing_reference.generate');
        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed dispatch must not leave half-built services behind that change the error on the next attempt',
        );
    }

    public function test_dependency_registered_through_factory_binding_is_resolved_at_dispatch(): void
    {
        putenv('ECOTONE_MISSING_REF_NS=App\MissingReference\Laravel\Shared');
        putenv('ECOTONE_MISSING_REF_REGISTER_FACTORY=1');

        $app = $this->bootApplication();

        $this->assertFalse(
            FactoryRegistrationProvider::$factoryInvoked,
            'Boot must stay lazy — the factory binding must not be resolved before a dispatched message needs it',
        );

        $this->assertSame(
            'monthly',
            $app->make(CommandBus::class)->sendWithRouting('missing_reference.generate', 'monthly'),
        );
        $this->assertTrue(
            FactoryRegistrationProvider::$factoryInvoked,
            'The collaborator must have been resolved through the registered factory binding',
        );
    }

    public function test_dependency_whose_own_dependency_does_not_exist_fails_at_dispatch(): void
    {
        putenv('ECOTONE_MISSING_REF_NS=App\MissingReference\Laravel\Chain');

        $app = $this->bootApplication();

        $exception = $this->dispatchAndCaptureFailure($app->make(CommandBus::class), 'missing_reference.chain');

        $this->assertStringContainsString(
            'NonExistingCollaborator',
            $exception->getMessage(),
            'The dispatch error must name what could not be resolved',
        );
    }

    private function dispatchAndCaptureFailure(CommandBus $commandBus, string $routingKey): Throwable
    {
        try {
            $commandBus->sendWithRouting($routingKey, 'monthly');
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Dispatch was expected to fail');
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
