<?php

declare(strict_types=1);

namespace Test\Ecotone\SymfonyContainer;

use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\Attribute\CommandHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Boot-time validation of external references for EcotoneLite bootstrapped
 * with an external PSR container. The probe is the container's own has() —
 * the PSR-11 capability contract — and never get(): a registered lazy factory
 * must count as available without being invoked. Unlike framework probes,
 * there is NO autowire fallback: Lite resolves references exclusively from
 * the provided container.
 *
 * licence Apache-2.0
 * @internal
 */
final class ExternalReferenceBootValidationTest extends TestCase
{
    public function test_missing_handler_dependency_fails_bootstrap_with_honest_error(): void
    {
        try {
            $this->bootstrapWith(new RecordingPsrContainer([
                BootValidatedHandler::class => fn () => new BootValidatedHandler(),
            ]));

            $this->fail('Bootstrap must fail: BootValidatedHandler references BootValidatedCollaborator which the container does not provide');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(
                'BootValidatedCollaborator',
                $exception->getMessage(),
                'The boot error must name the unresolvable reference',
            );
        }
    }

    public function test_dependency_registered_through_factory_passes_bootstrap_without_invoking_the_factory(): void
    {
        $container = new RecordingPsrContainer([
            BootValidatedHandler::class => fn () => new BootValidatedHandler(),
            BootValidatedCollaborator::class => function (): never {
                throw new RuntimeException('The factory must not be invoked during boot validation');
            },
        ]);

        $this->bootstrapWith($container);

        $this->assertNotContains(
            BootValidatedCollaborator::class,
            $container->invokedFactories,
            'Boot validation must accept a registered factory as proof of availability without invoking it',
        );
    }

    public function test_instantiable_class_absent_from_container_still_fails_because_lite_does_not_autowire(): void
    {
        try {
            $this->bootstrapWith(new RecordingPsrContainer([
                HandlerNeedingConcreteCollaborator::class => fn () => new HandlerNeedingConcreteCollaborator(),
            ]));

            $this->fail('Bootstrap must fail: ConcreteCollaborator is instantiable, but Lite resolves references only from the container');
        } catch (ConfigurationException $exception) {
            $this->assertStringContainsString(
                'ConcreteCollaborator',
                $exception->getMessage(),
                'The boot error must name the reference the container cannot provide',
            );
        }
    }

    public function test_failing_bootstrap_is_idempotent_and_reports_the_same_error_on_rerun(): void
    {
        $container = new RecordingPsrContainer([
            BootValidatedHandler::class => fn () => new BootValidatedHandler(),
        ]);

        $firstException = $this->bootstrapAndCaptureFailure($container);
        $secondException = $this->bootstrapAndCaptureFailure($container);

        $this->assertSame($firstException::class, $secondException::class);
        $this->assertSame(
            $firstException->getMessage(),
            $secondException->getMessage(),
            'A failed bootstrap must not leave state behind that changes the error on the next attempt',
        );
    }

    private function bootstrapAndCaptureFailure(ContainerInterface $container): Throwable
    {
        try {
            $this->bootstrapWith($container);
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Bootstrap was expected to fail');
    }

    private function bootstrapWith(RecordingPsrContainer $container): void
    {
        $classesToResolve = [];
        foreach (array_keys($container->factories) as $registeredId) {
            if (str_contains($registeredId, 'Handler')) {
                $classesToResolve[] = $registeredId;
            }
        }

        EcotoneLite::bootstrap(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: $container,
            configuration: ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackages()),
        );
    }
}

interface BootValidatedCollaborator
{
}

final class ConcreteCollaborator
{
}

final class BootValidatedHandler
{
    #[CommandHandler('boot_validation.lite.run')]
    public function run(string $payload, BootValidatedCollaborator $collaborator): string
    {
        return $payload;
    }
}

final class HandlerNeedingConcreteCollaborator
{
    #[CommandHandler('boot_validation.lite.concrete')]
    public function run(string $payload, ConcreteCollaborator $collaborator): string
    {
        return $payload;
    }
}

final class RecordingPsrContainer implements ContainerInterface
{
    /** @var string[] */
    public array $invokedFactories = [];

    /**
     * @param array<string, callable(): object> $factories
     */
    public function __construct(public readonly array $factories)
    {
    }

    public function get(string $id): mixed
    {
        if (! isset($this->factories[$id])) {
            throw new class ('Service not found: ' . $id) extends RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
            };
        }

        $this->invokedFactories[] = $id;

        return ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
