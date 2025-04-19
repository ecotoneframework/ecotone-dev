<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\DbalTransaction\DbalTransaction;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
/**
 * Module for registering the connection breaking interceptor
 */
class ConnectionBreakingModule implements AnnotationModule
{
    private ?ConnectionBreakingConfiguration $configuration = null;

    public function __construct()
    {
        // Default constructor
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    /**
     * Set the configuration for this module
     */
    public function withConfiguration(ConnectionBreakingConfiguration $configuration): self
    {
        $this->configuration = $configuration;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        // Find the configuration in the extension objects
        $configuration = $this->findConfiguration($extensionObjects);
        if (! $configuration) {
            return; // No configuration found, don't register the interceptor
        }

        // Define pointcuts for different interceptors
        $transactionPointcut = '(' . DbalTransaction::class . ')';
        $transactionPointcut .= '||(' . AsynchronousRunningEndpoint::class . ')';
        $transactionPointcut .= '||(' . CommandBus::class . ')';
        $transactionPointcut .= '||(' . ConsoleCommand::class . ')';

        $messageAcknowledgePointcut = '(' . AsynchronousRunningEndpoint::class . ')';

        $deadLetterStoragePointcut = '(' . AsynchronousRunningEndpoint::class . ')';

        // Get the connection factories
        $connectionFactories = [DbalConnectionFactory::class];

        // Register the interceptor service
        $messagingConfiguration->registerServiceDefinition(ConnectionBreakingInterceptor::class, [
            array_map(fn (string $id) => new Reference($id), $connectionFactories),
            $configuration->getBreakConnectionOnCalls(),
        ]);

        // Register interceptors based on configuration
        if ($configuration->shouldBreakBeforeCommit()) {
            // Register the interceptor with a higher precedence than the transaction interceptor
            $messagingConfiguration
                ->registerAroundMethodInterceptor(
                    AroundInterceptorBuilder::create(
                        ConnectionBreakingInterceptor::class,
                        $interfaceToCallRegistry->getFor(ConnectionBreakingInterceptor::class, 'breakConnection'),
                        Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1, // Higher precedence to run before the transaction interceptor
                        $transactionPointcut
                    )
                );
        }

        if ($configuration->shouldBreakBeforeMessageAcknowledge()) {
            // Register the interceptor with a higher precedence than the message acknowledge interceptor
            $messagingConfiguration
                ->registerAroundMethodInterceptor(
                    AroundInterceptorBuilder::create(
                        ConnectionBreakingInterceptor::class,
                        $interfaceToCallRegistry->getFor(ConnectionBreakingInterceptor::class, 'breakConnection'),
                        Precedence::MESSAGE_ACKNOWLEDGE_PRECEDENCE + 1, // Higher precedence to run before the message acknowledge
                        $messageAcknowledgePointcut
                    )
                );
        }
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof ConnectionBreakingConfiguration;
    }

    /**
     * Find the configuration in the extension objects
     */
    private function findConfiguration(array $extensionObjects): ?ConnectionBreakingConfiguration
    {
        foreach ($extensionObjects as $extensionObject) {
            if ($extensionObject instanceof ConnectionBreakingConfiguration) {
                return $extensionObject;
            }
        }

        return $this->configuration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
